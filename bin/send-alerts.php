<?php

declare(strict_types=1);

/**
 * Tâche planifiée (cron quotidien) : envoie le récapitulatif des alarmes par e-mail, tous sites.
 *   0 6 * * *  php /chemin/vers/perfectmaint/bin/send-alerts.php
 * N'envoie rien si les alarmes par e-mail sont désactivées (Paramètres > E-mails) ou s'il n'y a rien à signaler.
 *   --force  envoie même si les alarmes par e-mail sont désactivées
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Core\ValidationException;
use App\Security\SiteScope;
use App\Services\MailService;
use App\Services\ScheduledTaskService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';

$app = App::create($config, Session::memory(), new Database($config['db']), new Request('CLI', '/'));
// Exécution système : périmètre global.
$app->container()->set(SiteScope::class, SiteScope::all());
$mail = $app->container()->get(MailService::class);

$force = in_array('--force', $argv, true);
try {
    echo $app->container()->get(ScheduledTaskService::class)->run('alerts', static function () use ($mail, $force): string {
        if (!$mail->settings()['alerts_enabled'] && !$force) {
            return 'Alarmes par e-mail désactivées : aucun envoi (utilisez --force pour envoyer quand même).';
        }
        try {
            $count = $mail->sendAlerts();
        } catch (ValidationException $e) {
            throw new RuntimeException(implode(' ', $e->errors), 0, $e);
        }

        return $count > 0 ? "Récapitulatif envoyé : $count point(s) d'attention." : 'Rien à signaler : aucun e-mail envoyé.';
    }) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ECHEC : ' . $e->getMessage() . "\n");
    exit(1);
}
