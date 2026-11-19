<?php

declare(strict_types=1);

/**
 * Sauvegarde planifiée (base + fichiers joints) : à lancer chaque jour par le planificateur du serveur.
 *   php bin/backup.php            sauvegarde, puis purge des archives hors rétention
 *   php bin/backup.php --verify   sauvegarde puis test de restauration de l'archive (recommandé)
 *   php bin/backup.php --check    ne sauvegarde pas : code de sortie 1 si la dernière sauvegarde réussie est trop ancienne
 * Windows : schtasks /Create /SC DAILY /ST 23:00 /TN "ETHAN VRSC - Sauvegarde" /TR "C:\xampp\php\php.exe \"C:\chemin\bin\backup.php\" --verify"
 * Linux   : 0 23 * * *  php /chemin/bin/backup.php --verify
 * Code de sortie : 0 = succès, 1 = échec (à surveiller par le planificateur).
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Security\SiteScope;
use App\Services\BackupService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$app = App::create($config, Session::memory(), new Database($config['db']), new Request('CLI', '/'));
$app->container()->set(SiteScope::class, SiteScope::all());
$backups = $app->container()->get(BackupService::class);

if (in_array('--check', $argv, true)) {
    $health = $backups->health();
    if ($health['stale']) {
        fwrite(STDERR, "ALERTE : aucune sauvegarde réussie depuis plus de {$health['max_age_hours']} h.\n");
        exit(1);
    }
    echo 'Dernière sauvegarde réussie : ' . $health['last_ok']['created_at'] . "\n";
    exit(0);
}

try {
    $id = $backups->create('scheduled');
} catch (Throwable $e) {
    fwrite(STDERR, 'ECHEC de la sauvegarde : ' . $e->getMessage() . "\n");
    exit(1);
}
$backup = $backups->find($id);
echo "Sauvegarde créée : {$backup['file_name']} ({$backup['table_count']} tables, {$backup['row_count']} lignes, {$backup['file_count']} fichiers)\n";

if (in_array('--verify', $argv, true)) {
    $result = $backups->verify($id);
    if (!$result['ok']) {
        fwrite(STDERR, 'ECHEC du test de restauration : ' . $result['detail'] . "\n");
        exit(1);
    }
    echo 'Test de restauration réussi : ' . $result['detail'] . "\n";
}
