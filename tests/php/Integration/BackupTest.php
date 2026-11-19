<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Session;
use App\Core\ValidationException;
use App\Services\BackupService;
use Tests\DatabaseTestCase;

/**
 * Lot 8 — sauvegardes et restauration testée (recette R9) :
 * archive complète (base + fichiers joints + manifeste), test de restauration dans une base temporaire,
 * détection d'une archive altérée, restauration réelle dans une autre base, rétention, alerte d'ancienneté, droits.
 */
final class BackupTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CSRF = ['_csrf' => self::TOKEN];

    private string $dir;
    private int $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ethan-vrsc-backup-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/uploads/documents', 0777, true);
        file_put_contents($this->dir . '/uploads/documents/assurance.pdf', '%PDF-1.4 contenu de test');
        BackupService::useSettings(['dir' => $this->dir . '/archives', 'uploads_dir' => $this->dir . '/uploads', 'copy_dir' => $this->dir . '/copie', 'retention_days' => 30]);
        $this->admin = $this->createUser('admin', [], true);
        $this->createEquipment($this->createSite('KOLWEZI'), 'CAM-01', 'available', 100);
    }

    protected function tearDown(): void
    {
        BackupService::useSettings([]);
        $this->db->pdo()->exec('DROP DATABASE IF EXISTS vrsc_test_reprise');
        $this->db->pdo()->exec('DROP DATABASE IF EXISTS vrsc_test_restore_check');
        $this->remove($this->dir);
        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                $this->remove($path . '/' . $entry);
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }

    private function backups(): BackupService
    {
        return $this->service($this->admin, BackupService::class);
    }

    public function testBackupIsCompleteAndRestoreIsTested(): void
    {
        $service = $this->backups();
        self::assertTrue($service->health()['stale'], 'Sans sauvegarde, l\'état est en alerte');

        $id = $service->create('scheduled');
        $backup = $service->find($id);
        $path = $this->dir . '/archives/' . $backup['file_name'];
        self::assertSame(['scheduled', 'ok', 1, 1], [$backup['origin'], $backup['status'], (int) $backup['file_count'], (int) $backup['copied']]);
        self::assertFileExists($path);
        self::assertFileExists($this->dir . '/copie/' . $backup['file_name'], 'Copie hors serveur');
        self::assertSame(hash_file('sha256', $path), $backup['sha256']);

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertStringContainsString('CAM-01', (string) $zip->getFromName('database.sql'));
        self::assertSame('%PDF-1.4 contenu de test', $zip->getFromName('uploads/documents/assurance.pdf'));
        $zip->close();
        self::assertSame([1, 1], $manifest['tables']['equipment']);
        self::assertSame((int) $backup['table_count'], count($manifest['tables']));
        self::assertFalse($service->health()['stale']);
        self::assertNull($service->health()['last_verified']);

        // Test de restauration : base temporaire créée, comparée, puis supprimée ; la base de travail est intacte.
        $result = $service->verify($id);
        self::assertTrue($result['ok'], $result['detail']);
        $verified = $service->find($id);
        self::assertSame(['ok', $this->admin], [$verified['verify_status'], (int) $verified['verified_by']]);
        self::assertNull($this->value("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'vrsc_test_restore_check'"));
        self::assertSame(1, (int) $this->value('SELECT COUNT(*) FROM equipment'));
        self::assertSame($id, (int) $service->health()['last_verified']['id']);
        self::assertSame(['backup_create', 'backup_verify'], array_column($this->db->fetchAll("SELECT action FROM activity_log WHERE entity_type = 'backup' ORDER BY id"), 'action'));

        // Restauration réelle dans une autre base : les données d'origine y sont, même après une perte dans la base de travail.
        $this->db->execute('DELETE FROM equipment');
        $restored = $service->restoreInto($path, 'vrsc_test_reprise', false);
        self::assertSame($manifest['created_at'], $restored['created_at']);
        self::assertSame('CAM-01', $this->value('SELECT code FROM vrsc_test_reprise.equipment'));
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM equipment'));
        // Une table étrangère à la sauvegarde ne survit pas à une nouvelle restauration.
        $this->db->pdo()->exec('CREATE TABLE vrsc_test_reprise.intrus (id INT)');
        $service->restoreInto($path, 'vrsc_test_reprise', false);
        self::assertNull($this->value("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'vrsc_test_reprise' AND TABLE_NAME = 'intrus'"));
    }

    public function testDamagedOrInconsistentArchiveFailsTheRestoreTest(): void
    {
        $service = $this->backups();

        // Archive altérée après coup : l'empreinte ne correspond plus.
        $id = $service->create();
        $path = $this->dir . '/archives/' . $service->find($id)['file_name'];
        file_put_contents($path, 'x', FILE_APPEND);
        $result = $service->verify($id);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('Empreinte', $result['detail']);
        self::assertSame('failed', $service->find($id)['verify_status']);

        // Archive dont le contenu ne correspond pas au manifeste (lignes perdues) : détectée table par table.
        $second = $service->create();
        $backup = $service->find($second);
        $path = $this->dir . '/archives/' . $backup['file_name'];
        $zip = new \ZipArchive();
        $zip->open($path);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $manifest['tables']['equipment'] = [5, 5];
        $zip->addFromString('manifest.json', (string) json_encode($manifest));
        $zip->close();
        $this->db->execute('UPDATE backups SET sha256 = :h WHERE id = :id', ['h' => hash_file('sha256', $path), 'id' => $second]);
        $result = $service->verify($second);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('equipment', $result['detail']);
        self::assertNull($this->value("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'vrsc_test_restore_check'"));

        // Échec de la sauvegarde elle-même (outil introuvable) : journalisé, signalé dans l'état de santé.
        BackupService::useSettings(['dir' => $this->dir . '/archives', 'uploads_dir' => $this->dir . '/uploads', 'bin' => $this->dir . '/absent']);
        try {
            $service->create('scheduled');
            self::fail('Sauvegarde réussie sans outil');
        } catch (\RuntimeException) {
        }
        $health = $service->health();
        self::assertNotNull($health['last_failed']);
        self::assertSame('failed', $this->value('SELECT status FROM backups ORDER BY id DESC LIMIT 1'));
        self::assertCount(2, glob($this->dir . '/archives/*.zip'), 'Aucune archive partielle laissée sur le disque');
    }

    public function testRetentionKeepsTheLatestArchivesAndStaleBackupRaisesAnAlert(): void
    {
        $service = $this->backups();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $service->create();
        }
        // Toutes vieillies de 60 jours : seules les 3 dernières réussies restent, malgré leur âge.
        $this->db->execute('UPDATE backups SET created_at = NOW() - INTERVAL 60 DAY');
        self::assertSame(2, $service->prune());
        self::assertCount(3, glob($this->dir . '/archives/*.zip'));
        self::assertSame([$ids[0], $ids[1]], array_map('intval', array_column($this->db->fetchAll('SELECT id FROM backups WHERE pruned_at IS NOT NULL ORDER BY id'), 'id')));
        try {
            $service->path($service->find($ids[0]));
            self::fail('Archive supprimée encore proposée');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('backup', $e->errors);
        }
        // Dernière sauvegarde trop ancienne : alerte.
        self::assertTrue($service->health()['stale']);
        $service->create();
        self::assertFalse($service->health()['stale']);
    }

    public function testBackupsOverHttpAreAdminOnly(): void
    {
        $admin = Session::memory(['auth_user_id' => $this->admin, '_csrf_token' => self::TOKEN]);
        $page = $this->http($admin, 'GET', '/backups')->body;
        self::assertStringContainsString('Aucune sauvegarde réussie', $page);
        self::assertStringContainsString('</html>', $page);

        self::assertSame(302, $this->http($admin, 'POST', '/backups', self::CSRF)->status);
        $id = (int) $this->value('SELECT id FROM backups');
        self::assertSame('manual', $this->value('SELECT origin FROM backups'));
        $page = $this->http($admin, 'GET', '/backups')->body;
        self::assertStringContainsString("/backups/$id/verify", $page);
        self::assertStringContainsString('Non testée', $page);

        self::assertSame(302, $this->http($admin, 'POST', "/backups/$id/verify", self::CSRF)->status);
        self::assertStringContainsString('restaurées et conformes', $this->http($admin, 'GET', '/backups')->body);
        $download = $this->http($admin, 'GET', "/backups/$id/download");
        self::assertSame(200, $download->status);
        self::assertSame('application/zip', $download->headers['Content-Type']);
        self::assertStringStartsWith('PK', $download->body);

        // Réservé à l'administrateur ; jeton obligatoire.
        foreach (['manager', 'logistics', 'finance', 'director'] as $role) {
            $session = Session::memory(['auth_user_id' => $this->createUser($role, [], true), '_csrf_token' => self::TOKEN]);
            self::assertSame(403, $this->http($session, 'GET', '/backups')->status, $role);
            self::assertSame(403, $this->http($session, 'POST', '/backups', self::CSRF)->status, $role);
            self::assertSame(403, $this->http($session, 'GET', "/backups/$id/download")->status, $role);
        }
        self::assertSame(419, $this->http($admin, 'POST', '/backups')->status);
        self::assertSame(404, $this->http($admin, 'GET', '/backups/999999/download')->status);
    }
}
