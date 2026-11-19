<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\BackupService;

/** Sauvegardes : journal, lancement manuel, test de restauration, téléchargement de l'archive (administrateur). */
final class BackupController extends Controller
{
    /** Au-delà, l'archive se récupère directement dans le dossier du serveur. */
    private const MAX_DOWNLOAD_BYTES = 300 * 1024 * 1024;

    public function __construct(ControllerContext $ctx, private readonly BackupService $backups)
    {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $settings = BackupService::settings();

        return $this->render('backups/index', [
            'title' => t('nav.backups'),
            'backups' => $this->backups->all(),
            'health' => $this->backups->health(),
            'settings' => ['dir' => $settings['dir'], 'copy_dir' => $settings['copy_dir'], 'retention_days' => $settings['retention_days']],
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $this->backups->create('manual');
        } catch (\RuntimeException $e) {
            throw Validator::fail('backup', 'backup_failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
        }

        return $this->redirect('/backups', t('flash.backup_created'));
    }

    public function verify(Request $request, array $params): Response
    {
        $result = $this->backups->verify($this->id($params));
        if (!$result['ok']) {
            throw Validator::fail('backup', 'backup_verify_failed', ['error' => mb_substr($result['detail'], 0, 300)]);
        }

        return $this->redirect('/backups', t('flash.backup_verified'));
    }

    public function download(Request $request, array $params): Response
    {
        $backup = $this->backups->find($this->id($params));
        $path = $this->backups->path($backup);
        if (filesize($path) > self::MAX_DOWNLOAD_BYTES) {
            throw Validator::fail('backup', 'backup_too_large');
        }

        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . basename((string) $backup['file_name']) . '"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
