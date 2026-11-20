<?php

declare(strict_types=1);

/**
 * Tâche planifiée (quotidienne, 05:00) : crée les OT préventifs des plans à échéance, tous sites.
 * Installation Windows : bin/install-tasks.ps1 · Linux : 0 5 * * *  php /chemin/bin/generate-preventive.php
 * Chaque passage est journalisé (Administration > Tâches planifiées).
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Security\SiteScope;
use App\Services\PreventiveService;
use App\Services\ScheduledTaskService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$horizon = (int) ($argv[1] ?? PreventiveService::DEFAULT_HORIZON_DAYS);
$app = App::create($config, Session::memory(), new Database($config['db']), new Request('CLI', '/'));
// Exécution système : périmètre global, journalisé sous l'utilisateur « system ».
$app->container()->set(SiteScope::class, SiteScope::all());

try {
    echo $app->container()->get(ScheduledTaskService::class)->run('preventive', static function () use ($app, $horizon): string {
        $created = $app->container()->get(PreventiveService::class)->generateDue($horizon);

        return count($created) . ' OT préventif(s) créé(s).';
    }) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ECHEC : ' . $e->getMessage() . "\n");
    exit(1);
}
