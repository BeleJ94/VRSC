<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AttachmentService;

/** Consultation d'une pièce jointe, après contrôle du périmètre de sites. */
final class AttachmentController extends Controller
{
    public function __construct(ControllerContext $ctx, private readonly AttachmentService $attachments)
    {
        parent::__construct($ctx);
    }

    public function show(Request $request, array $params): Response
    {
        $file = $this->attachments->file($this->id($params));
        $disposition = $request->string('download') === '1' ? 'attachment' : 'inline';

        return new Response((string) file_get_contents($file['path']), 200, [
            'Content-Type' => $file['mime'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => $disposition . "; filename*=UTF-8''" . rawurlencode($file['name']),
        ]);
    }
}
