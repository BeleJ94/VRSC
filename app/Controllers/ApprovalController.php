<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ApprovalService;
use App\Services\ApprovalWorkflow;

/** « Mes validations » : demandes que l'utilisateur peut traiter, et enregistrement d'un acte. */
final class ApprovalController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly ApprovalService $approvals,
        private readonly ApprovalWorkflow $workflow,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $inbox = array_map(static fn (array $r): array => $r + ['page' => ApprovalWorkflow::page((string) $r['entity_type'], (int) $r['entity_id'])], $this->approvals->inbox());

        return $this->render('approvals/index', ['title' => t('nav.approvals'), 'inbox' => $inbox]);
    }

    public function act(Request $request, array $params): Response
    {
        $result = $this->workflow->act($this->id($params), $request->body);
        $page = ApprovalWorkflow::page($result['entity_type'], $result['entity_id']) ?? '/approvals';

        $flash = ['pending' => 'flash.approval_pending', 'approved' => 'flash.approval_approved', 'rejected' => 'flash.approval_rejected'];

        return $this->redirect($page, t($flash[$result['status']] ?? 'flash.updated'));
    }
}
