<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Core\Validator;
use App\Security\Auth;

/**
 * Sauvegardes et restauration testée (cahier des charges, recette R9).
 *
 * - Une sauvegarde = une archive ZIP : la base (database.sql, via mysqldump en instantané cohérent), les fichiers
 *   joints (uploads/) et un manifeste (nombre de lignes par table). Empreinte SHA-256 journalisée.
 * - Test de restauration : l'archive est contrôlée (empreinte), restaurée dans une base temporaire, puis chaque table
 *   est comparée au manifeste. Une sauvegarde n'est réputée bonne qu'une fois ce test réussi.
 * - Rétention : les archives plus anciennes que le délai sont supprimées, en gardant toujours les dernières.
 * - La restauration réelle se fait en ligne de commande (bin/restore.php), après une sauvegarde de précaution.
 */
final class BackupService
{
    /** Nombre d'archives réussies toujours conservées, quel que soit leur âge. */
    public const KEEP_AT_LEAST = 3;

    /** Réglages forcés (tests). */
    private static array $overrides = [];

    public function __construct(
        private readonly Database $db,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    public static function useSettings(array $overrides): void
    {
        self::$overrides = $overrides;
    }

    /** @return array{dir: string, uploads_dir: string, copy_dir: ?string, retention_days: int, max_age_hours: int, bin: string, db: array} */
    public static function settings(): array
    {
        $root = dirname(__DIR__, 2);
        $bin = Env::get('MYSQL_BIN');
        if ($bin === null || $bin === '') {
            // XAMPP n'ajoute pas les outils MariaDB au PATH.
            $bin = is_file('C:/xampp/mysql/bin/mysqldump.exe') ? 'C:/xampp/mysql/bin' : '';
        }

        return self::$overrides + [
            'dir' => Env::get('BACKUP_DIR') ?: $root . '/storage/backups',
            'uploads_dir' => $root . '/storage/uploads',
            'copy_dir' => Env::get('BACKUP_COPY_DIR') ?: null,
            'retention_days' => max(1, (int) (Env::get('BACKUP_RETENTION_DAYS') ?: 30)),
            'max_age_hours' => max(1, (int) (Env::get('BACKUP_MAX_AGE_HOURS') ?: 26)),
            'bin' => rtrim((string) $bin, '/\\'),
            'db' => [
                'host' => Env::get('DB_HOST', '127.0.0.1'), 'port' => (int) Env::get('DB_PORT', '3306'),
                'name' => Env::get('DB_NAME', 'vrsc'), 'user' => Env::get('DB_USER', 'root'), 'pass' => (string) Env::get('DB_PASS', ''),
            ],
        ];
    }

    /** Journal des sauvegardes, la plus récente d'abord. */
    public function all(int $limit = 100): array
    {
        return $this->db->fetchAll(
            "SELECT b.*, CONCAT(u.first_name, ' ', u.last_name) AS author, CONCAT(v.first_name, ' ', v.last_name) AS verifier
               FROM backups b LEFT JOIN users u ON u.id = b.created_by LEFT JOIN users v ON v.id = b.verified_by
              ORDER BY b.id DESC LIMIT " . max(1, min(500, $limit))
        );
    }

    /**
     * État de santé : dernière sauvegarde réussie, dernière restauration testée, alerte si la sauvegarde est trop ancienne.
     *
     * @return array{last_ok: ?array, last_verified: ?array, last_failed: ?array, stale: bool, max_age_hours: int}
     */
    public function health(): array
    {
        $settings = self::settings();
        $lastOk = $this->db->fetchOne("SELECT * FROM backups WHERE status = 'ok' ORDER BY id DESC LIMIT 1");
        $lastFailed = $this->db->fetchOne("SELECT * FROM backups WHERE status = 'failed' ORDER BY id DESC LIMIT 1");

        return [
            'last_ok' => $lastOk,
            'last_verified' => $this->db->fetchOne("SELECT * FROM backups WHERE verify_status = 'ok' ORDER BY verified_at DESC LIMIT 1"),
            'last_failed' => $lastFailed !== null && ($lastOk === null || (int) $lastFailed['id'] > (int) $lastOk['id']) ? $lastFailed : null,
            'stale' => $lastOk === null || strtotime((string) $lastOk['created_at']) < time() - $settings['max_age_hours'] * 3600,
            'max_age_hours' => $settings['max_age_hours'],
        ];
    }

    /**
     * Crée une sauvegarde complète. En cas d'échec, l'échec est journalisé puis l'exception est relancée.
     *
     * @return int identifiant de la sauvegarde
     */
    public function create(string $origin = 'manual'): int
    {
        @set_time_limit(0);
        $settings = self::settings();
        $started = microtime(true);
        $name = sprintf('ethan-vrsc-%s-%s-%s.zip', preg_replace('/[^A-Za-z0-9_]/', '', $settings['db']['name']), date('Ymd-His'), substr(bin2hex(random_bytes(3)), 0, 5));
        $target = $settings['dir'] . '/' . $name;
        $sql = $settings['dir'] . '/' . $name . '.sql.tmp';
        try {
            if (!is_dir($settings['dir']) && !mkdir($settings['dir'], 0770, true) && !is_dir($settings['dir'])) {
                throw new \RuntimeException('Dossier de sauvegarde inaccessible : ' . $settings['dir']);
            }
            // Comptage avant et après l'export : une table modifiée pendant l'export est tolérée dans cet intervalle.
            $before = $this->counts($settings['db']['name']);
            $this->run($settings, 'mysqldump', ['--single-transaction', '--routines', '--triggers', '--default-character-set=utf8mb4',
                '--result-file=' . $sql, $settings['db']['name']]);
            if (!is_file($sql) || filesize($sql) < 100) {
                throw new \RuntimeException('Export de la base vide ou absent.');
            }
            $after = $this->counts($settings['db']['name']);
            $tables = [];
            foreach ($before as $table => $count) {
                $tables[$table] = [min($count, $after[$table] ?? $count), max($count, $after[$table] ?? $count)];
            }
            $files = $this->uploads($settings['uploads_dir']);
            $manifest = [
                'application' => 'ETHAN VRSC', 'database' => $settings['db']['name'], 'created_at' => date('c'),
                'last_migration' => $this->db->fetchValue('SELECT MAX(migration) FROM schema_migrations'),
                'tables' => $tables, 'files' => count($files),
            ];
            $zip = new \ZipArchive();
            if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
                throw new \RuntimeException("Création de l'archive impossible : $target");
            }
            $zip->addFile($sql, 'database.sql');
            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            foreach ($files as $relative => $path) {
                $zip->addFile($path, 'uploads/' . $relative);
            }
            if (!$zip->close()) {
                throw new \RuntimeException("Écriture de l'archive impossible : $target");
            }
            @unlink($sql);
            $copied = $this->copy($settings['copy_dir'], $target, $name);
            $id = $this->db->insert(
                'INSERT INTO backups (file_name, origin, status, size_bytes, sha256, table_count, row_count, file_count, duration_s, copied, created_by)
                 VALUES (:f, :o, \'ok\', :s, :h, :t, :r, :n, :d, :c, :u)',
                ['f' => $name, 'o' => $origin, 's' => filesize($target), 'h' => hash_file('sha256', $target), 't' => count($tables),
                    'r' => array_sum(array_column($tables, 1)), 'n' => count($files), 'd' => (int) round(microtime(true) - $started),
                    'c' => $copied ? 1 : 0, 'u' => $this->auth->user()?->id]
            );
            $this->logger->log('backup_create', 'backup', $id, null, null, ['file' => $name, 'origin' => $origin, 'tables' => count($tables), 'files' => count($files), 'copied' => $copied], $name);
            $this->prune();

            return $id;
        } catch (\Throwable $e) {
            @unlink($sql);
            @unlink($target);
            $this->db->insert(
                "INSERT INTO backups (file_name, origin, status, error, duration_s, created_by) VALUES (:f, :o, 'failed', :e, :d, :u)",
                ['f' => $name, 'o' => $origin, 'e' => mb_substr($e->getMessage(), 0, 2000), 'd' => (int) round(microtime(true) - $started), 'u' => $this->auth->user()?->id]
            );
            throw $e;
        }
    }

    /**
     * Test de restauration : empreinte, restauration dans une base temporaire, comparaison table par table.
     *
     * @return array{ok: bool, detail: string}
     */
    public function verify(int $id): array
    {
        @set_time_limit(0);
        $backup = $this->find($id);
        $settings = self::settings();
        $scratch = substr($settings['db']['name'], 0, 40) . '_restore_check';
        $detail = [];
        $ok = false;
        try {
            $path = $this->path($backup);
            if (!hash_equals((string) $backup['sha256'], (string) hash_file('sha256', $path))) {
                throw new \RuntimeException(t('backups.error_checksum'));
            }
            $manifest = $this->restoreInto($path, $scratch, false);
            $restored = $this->counts($scratch);
            $problems = [];
            foreach ($manifest['tables'] as $table => [$min, $max]) {
                if (!array_key_exists($table, $restored)) {
                    $problems[] = t('backups.error_table_missing', ['table' => $table]);
                } elseif ($restored[$table] < $min || $restored[$table] > $max) {
                    $problems[] = t('backups.error_table_rows', ['table' => $table, 'expected' => $min === $max ? $min : "$min–$max", 'found' => $restored[$table]]);
                }
            }
            if ($problems !== []) {
                throw new \RuntimeException(implode(' ', $problems));
            }
            $ok = true;
            $detail[] = t('backups.verify_ok', ['tables' => count($restored), 'rows' => format_number(array_sum($restored)), 'files' => (int) ($manifest['files'] ?? 0)]);
        } catch (\Throwable $e) {
            $detail[] = $e->getMessage();
        } finally {
            $this->dropDatabase($scratch);
        }
        $text = mb_substr(implode(' ', $detail), 0, 2000);
        $this->db->execute(
            'UPDATE backups SET verified_at = NOW(), verify_status = :s, verify_detail = :d, verified_by = :u WHERE id = :id',
            ['s' => $ok ? 'ok' : 'failed', 'd' => $text, 'u' => $this->auth->user()?->id, 'id' => $id]
        );
        $this->logger->log('backup_verify', 'backup', $id, null, null, ['file' => $backup['file_name'], 'result' => $ok ? 'ok' : 'failed', 'detail' => $text], $backup['file_name']);

        return ['ok' => $ok, 'detail' => $text];
    }

    /**
     * Restaure une archive dans une base (vidée au préalable). Réservé au test de restauration et à bin/restore.php.
     *
     * @return array manifeste de l'archive
     */
    public function restoreInto(string $zipPath, string $database, bool $withFiles): array
    {
        @set_time_limit(0);
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $database)) {
            throw new \InvalidArgumentException('Nom de base invalide.');
        }
        $settings = self::settings();
        $zip = new \ZipArchive();
        if (!is_file($zipPath) || $zip->open($zipPath) !== true) {
            throw new \RuntimeException(t('backups.error_archive'));
        }
        $work = $settings['dir'] . '/restore-' . bin2hex(random_bytes(6));
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (!is_array($manifest) || !isset($manifest['tables']) || $zip->locateName('database.sql') === false) {
                throw new \RuntimeException(t('backups.error_archive'));
            }
            if (!mkdir($work, 0770, true) || !$zip->extractTo($work, 'database.sql')) {
                throw new \RuntimeException(t('backups.error_archive'));
            }
            $pdo = $this->db->pdo();
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            // Base cible vidée : aucune table étrangère à la sauvegarde ne doit subsister.
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach (array_keys($this->counts($database)) as $table) {
                    $pdo->exec("DROP TABLE `$database`.`$table`");
                }
            } finally {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            $this->run($settings, 'mysql', ['--default-character-set=utf8mb4', $database], $work . '/database.sql');
            if ($withFiles) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = (string) $zip->getNameIndex($i);
                    if (str_starts_with($entry, 'uploads/') && !str_ends_with($entry, '/') && !str_contains($entry, '..')) {
                        $dest = $settings['uploads_dir'] . '/' . substr($entry, 8);
                        if (!is_dir(dirname($dest))) {
                            mkdir(dirname($dest), 0775, true);
                        }
                        file_put_contents($dest, $zip->getFromIndex($i));
                    }
                }
            }

            return $manifest;
        } finally {
            $zip->close();
            @unlink($work . '/database.sql');
            @rmdir($work);
        }
    }

    /** Supprime les archives plus anciennes que la rétention, en gardant toujours les dernières réussies. @return int nombre supprimé */
    public function prune(): int
    {
        $settings = self::settings();
        $keep = array_map('intval', array_column($this->db->fetchAll("SELECT id FROM backups WHERE status = 'ok' AND pruned_at IS NULL ORDER BY id DESC LIMIT " . self::KEEP_AT_LEAST), 'id'));
        $old = $this->db->fetchAll(
            "SELECT id, file_name FROM backups WHERE status = 'ok' AND pruned_at IS NULL AND created_at < NOW() - INTERVAL :d DAY",
            ['d' => $settings['retention_days']]
        );
        $count = 0;
        foreach ($old as $row) {
            if (in_array((int) $row['id'], $keep, true)) {
                continue;
            }
            @unlink($settings['dir'] . '/' . basename((string) $row['file_name']));
            $this->db->execute('UPDATE backups SET pruned_at = NOW() WHERE id = :id', ['id' => $row['id']]);
            $count++;
        }
        if ($count > 0) {
            $this->logger->log('backup_prune', 'backup', null, null, null, ['removed' => $count, 'retention_days' => $settings['retention_days']], (string) $count);
        }

        return $count;
    }

    public function find(int $id): array
    {
        return $this->db->fetchOne('SELECT * FROM backups WHERE id = :id', ['id' => $id]) ?? throw HttpException::notFound();
    }

    /** Chemin de l'archive d'une sauvegarde réussie encore présente sur le disque. */
    public function path(array $backup): string
    {
        $path = self::settings()['dir'] . '/' . basename((string) $backup['file_name']);
        if ($backup['status'] !== 'ok' || $backup['pruned_at'] !== null || !is_file($path)) {
            throw Validator::fail('backup', 'backup_file_missing');
        }

        return $path;
    }

    /** @return array<string, int> table → nombre de lignes */
    private function counts(string $database): array
    {
        $tables = $this->db->fetchAll(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = :s AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            ['s' => $database]
        );
        $counts = [];
        foreach ($tables as $table) {
            $name = (string) $table['name'];
            if (preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                $counts[$name] = (int) $this->db->fetchValue("SELECT COUNT(*) FROM `$database`.`$name`");
            }
        }

        return $counts;
    }

    private function dropDatabase(string $database): void
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) && str_ends_with($database, '_restore_check')) {
            $this->db->pdo()->exec("DROP DATABASE IF EXISTS `$database`");
        }
    }

    /** @return array<string, string> chemin relatif → chemin absolu des fichiers joints */
    private function uploads(string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== '.gitkeep') {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1))] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    /** Copie hors du serveur (disque externe, partage réseau) quand BACKUP_COPY_DIR est renseigné. */
    private function copy(?string $copyDir, string $source, string $name): bool
    {
        if ($copyDir === null || $copyDir === '') {
            return false;
        }
        if ((!is_dir($copyDir) && !@mkdir($copyDir, 0770, true)) || !@copy($source, rtrim($copyDir, '/\\') . '/' . $name)) {
            throw new \RuntimeException('Copie de la sauvegarde impossible vers : ' . $copyDir);
        }

        return true;
    }

    /** Exécute un outil MariaDB sans passer par un interpréteur de commandes ; le mot de passe transite par l'environnement. */
    private function run(array $settings, string $tool, array $args, ?string $stdinFile = null): void
    {
        $binary = ($settings['bin'] !== '' ? $settings['bin'] . '/' : '') . $tool . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        $command = array_merge([$binary, '--host=' . $settings['db']['host'], '--port=' . $settings['db']['port'], '--user=' . $settings['db']['user']], $args);
        $env = getenv();
        if ($settings['db']['pass'] !== '') {
            $env['MYSQL_PWD'] = $settings['db']['pass'];
        }
        $process = @proc_open($command, [0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException("Outil introuvable : $binary (réglage MYSQL_BIN)");
        }
        if ($stdinFile === null) {
            fclose($pipes[0]);
        }
        $error = stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new \RuntimeException(trim("$tool : " . ($error !== '' && $error !== false ? $error : "code de sortie $code")));
        }
    }
}
