<?php

declare(strict_types=1);

/**
 * Restauration d'une sauvegarde (procédure de reprise après incident).
 *   php bin/restore.php <archive.zip> --database=vrsc_reprise     restaure dans une autre base (sans risque)
 *   php bin/restore.php <archive.zip> --force [--with-files]      remplace la base de l'application
 * Avec --force, une sauvegarde de précaution de l'état actuel est créée d'abord ; la base est ensuite vidée et remplacée.
 * --with-files remet aussi les fichiers joints de l'archive dans storage/uploads.
 * Après une restauration : php bin/migrate.php (applique les migrations plus récentes que la sauvegarde).
 */

use App\Core\App;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use App\Security\SiteScope;
use App\Services\BackupService;

$config = require dirname(__DIR__) . '/app/bootstrap.php';
$file = null;
$database = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--database=')) {
        $database = substr($arg, 11);
    } elseif (!str_starts_with($arg, '--')) {
        $file = $arg;
    }
}
$force = in_array('--force', $argv, true);
$live = $database === null || $database === $config['db']['name'];
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "Usage : php bin/restore.php <archive.zip> [--database=nom] [--force] [--with-files]\n");
    exit(2);
}
if ($live && !$force) {
    fwrite(STDERR, "Refusé : restaurer dans la base de l'application ({$config['db']['name']}) la remplace entièrement. Ajoutez --force, ou --database=autre_base pour un essai.\n");
    exit(2);
}

$app = App::create($config, Session::memory(), new Database($config['db']), new Request('CLI', '/'));
$app->container()->set(SiteScope::class, SiteScope::all());
$backups = $app->container()->get(BackupService::class);
$journal = null;

try {
    if ($live) {
        $safety = $backups->find($backups->create('pre_restore'));
        echo "Sauvegarde de précaution créée : {$safety['file_name']}\n";
        // Le journal des sauvegardes survit à la restauration : il est réinjecté ensuite.
        $journal = (new Database($config['db']))->fetchAll('SELECT * FROM backups');
    }
    $manifest = $backups->restoreInto($file, $database ?? $config['db']['name'], in_array('--with-files', $argv, true));
    if ($journal !== null) {
        $db = new Database($config['db']);
        foreach ($journal as $row) {
            $row['created_by'] = $row['verified_by'] = null; // les comptes restaurés peuvent différer
            $columns = array_keys($row);
            $db->execute('REPLACE INTO backups (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')', $row);
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ECHEC de la restauration : ' . $e->getMessage() . "\n");
    exit(1);
}
echo 'Restauration terminée dans la base « ' . ($database ?? $config['db']['name']) . ' » : ' . count($manifest['tables']) . ' tables, sauvegarde du ' . $manifest['created_at'] . ".\n";
echo "Pensez à lancer : php bin/migrate.php\n";
