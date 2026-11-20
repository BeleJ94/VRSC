<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Tâches planifiées quotidiennes : définition (script, heure), journal des exécutions et état de santé.
 * Les scripts bin/ enregistrent chaque passage via run() ; l'installation Windows est faite par bin/install-tasks.ps1.
 */
final class ScheduledTaskService
{
    /** Une tâche quotidienne est « en retard » sans exécution réussie depuis ce délai (heures). */
    public const MAX_AGE_HOURS = 26;

    /** Ordre d'exécution dans la journée : sauvegarde la nuit, puis OT préventifs, puis récapitulatif des alertes. */
    public const TASKS = [
        'preventive' => ['script' => 'generate-preventive.php', 'args' => '', 'time' => '05:00', 'icon' => 'calendar-clock'],
        'alerts' => ['script' => 'send-alerts.php', 'args' => '', 'time' => '06:00', 'icon' => 'bell-ring'],
        'backup' => ['script' => 'backup.php', 'args' => '--verify', 'time' => '23:00', 'icon' => 'database-backup'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Exécute une tâche en la journalisant : le message retourné est conservé, une exception marque l'échec
     * (et est relancée pour que le script sorte en erreur).
     */
    public function run(string $task, callable $job, string $origin = 'scheduled'): string
    {
        $id = $this->db->insert(
            'INSERT INTO scheduled_runs (task, origin, status, started_at) VALUES (:t, :o, :s, NOW())',
            ['t' => $task, 'o' => $origin === 'manual' ? 'manual' : 'scheduled', 's' => 'running']
        );
        try {
            $message = (string) $job();
        } catch (\Throwable $e) {
            $this->finish($id, 'failed', $e->getMessage());
            throw $e;
        }
        $this->finish($id, 'ok', $message);

        return $message;
    }

    private function finish(int $id, string $status, string $message): void
    {
        $this->db->execute(
            'UPDATE scheduled_runs SET status = :s, message = :m, finished_at = NOW() WHERE id = :id',
            ['s' => $status, 'm' => mb_substr(trim($message), 0, 500), 'id' => $id]
        );
    }

    /**
     * État de chaque tâche : dernière exécution, dernière réussite et santé (ok, late, failed, never).
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $rows = [];
        foreach (self::TASKS as $key => $def) {
            $last = $this->db->fetchOne('SELECT * FROM scheduled_runs WHERE task = :t ORDER BY started_at DESC, id DESC LIMIT 1', ['t' => $key]);
            $lastOk = $this->db->fetchOne("SELECT * FROM scheduled_runs WHERE task = :t AND status = 'ok' ORDER BY started_at DESC, id DESC LIMIT 1", ['t' => $key]);
            $rows[] = $def + ['key' => $key, 'last' => $last, 'last_ok' => $lastOk, 'health' => self::health($last, $lastOk)];
        }

        return $rows;
    }

    /** Nombre de tâches à surveiller (en échec, en retard ou jamais exécutées). */
    public function problems(): int
    {
        return count(array_filter($this->overview(), static fn (array $r): bool => $r['health'] !== 'ok'));
    }

    public static function health(?array $last, ?array $lastOk, ?int $now = null): string
    {
        $now ??= time();
        if ($last === null) {
            return 'never';
        }
        if ($last['status'] === 'failed') {
            return 'failed';
        }
        if ($lastOk === null || strtotime((string) $lastOk['started_at']) < $now - self::MAX_AGE_HOURS * 3600) {
            return 'late';
        }

        return 'ok';
    }

    /** Dernières exécutions, toutes tâches confondues. */
    public function recent(int $limit = 30): array
    {
        return $this->db->fetchAll('SELECT * FROM scheduled_runs ORDER BY started_at DESC, id DESC LIMIT ' . max(1, min(200, $limit)));
    }
}
