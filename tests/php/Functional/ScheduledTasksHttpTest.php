<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Core\Session;
use App\Services\ScheduledTaskService;
use Tests\DatabaseTestCase;

/** Point 3.2 : tâches planifiées (journal, santé, lancement manuel) et page d'administration. */
final class ScheduledTasksHttpTest extends DatabaseTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private function session(string $role): Session
    {
        return Session::memory(['auth_user_id' => $this->createUser($role, [], true), '_csrf_token' => self::TOKEN]);
    }

    public function testRunsAreJournaledAndFailuresKept(): void
    {
        $tasks = new ScheduledTaskService($this->db);
        self::assertSame('Fait', $tasks->run('preventive', static fn (): string => 'Fait'));
        try {
            $tasks->run('alerts', static function (): string {
                throw new \RuntimeException('SMTP <refusé>');
            });
            self::fail('L\'échec est relancé pour que le script sorte en erreur');
        } catch (\RuntimeException) {
        }
        $health = array_column($tasks->overview(), 'health', 'key');
        self::assertSame(['preventive' => 'ok', 'alerts' => 'failed', 'backup' => 'never'], $health);
        self::assertSame(2, $tasks->problems());

        $old = ['status' => 'ok', 'started_at' => date('Y-m-d H:i:s', time() - 27 * 3600)];
        self::assertSame('late', ScheduledTaskService::health($old, $old), 'Plus de 26 h sans réussite');
    }

    public function testAdminPageShowsHealthAndRunsAJobManually(): void
    {
        $admin = $this->session('admin');
        $page = $this->http($admin, 'GET', '/settings/tasks')->body;
        foreach (['Tâches planifiées', 'OT préventifs', 'Alertes par e-mail', 'Sauvegarde', 'Jamais exécutée', 'install-tasks.ps1', '3 tâche(s) à surveiller'] as $needle) {
            self::assertStringContainsString($needle, $page, $needle);
        }

        $res = $this->http($admin, 'POST', '/settings/tasks/preventive/run', ['_csrf' => self::TOKEN]);
        self::assertSame(302, $res->status);
        $run = $this->db->fetchOne('SELECT * FROM scheduled_runs ORDER BY id DESC LIMIT 1');
        self::assertSame(['preventive', 'manual', 'ok', '0 OT préventif(s) créé(s).'], [$run['task'], $run['origin'], $run['status'], $run['message']]);
        self::assertStringContainsString('À jour', $this->http($admin, 'GET', '/settings/tasks')->body);

        self::assertSame(404, $this->http($admin, 'POST', '/settings/tasks/backup/run', ['_csrf' => self::TOKEN])->status, 'La sauvegarde se lance depuis sa page');
        self::assertSame(403, $this->http($this->session('logistics'), 'GET', '/settings/tasks')->status);
    }
}
