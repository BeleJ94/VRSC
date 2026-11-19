<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DataTableRequest;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\FinanceRepository;
use App\Repositories\PartRepository;
use App\Repositories\ReferenceRepository;
use App\Repositories\SiteRepository;
use App\Services\ApprovalService;
use App\Services\AttachmentService;
use App\Services\CashService;
use App\Services\PaymentService;
use App\Services\StockPeriodService;

/** Finance : tableau de bord, factures, demandes de décaissement, caisse, rapprochement et états de dépenses. */
final class FinanceController extends Controller
{
    public function __construct(
        ControllerContext $ctx,
        private readonly FinanceRepository $finance,
        private readonly PaymentService $payments,
        private readonly CashService $cash,
        private readonly ApprovalService $approvals,
        private readonly AttachmentService $attachments,
        private readonly SiteRepository $sites,
        private readonly ReferenceRepository $references,
        private readonly PartRepository $parts,
        private readonly Database $db,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $siteId = $this->siteFilter($request);

        return $this->render('finance/index', [
            'title' => t('nav.finance'),
            'summary' => $this->finance->dashboard($siteId),
            'toPay' => $this->finance->payments(['status' => 'approved', 'site_id' => $siteId]),
            'unpaid' => array_slice($this->finance->invoices(['unpaid' => true, 'site_id' => $siteId]), 0, 15),
            'advances' => $this->finance->payments(['to_justify' => true, 'site_id' => $siteId]),
            'registers' => $this->finance->registers($siteId),
        ]);
    }

    // --- Factures -------------------------------------------------------------------

    public function invoices(Request $request): Response
    {
        $orders = $this->db->fetchAll(
            "SELECT o.id, o.reference, sup.name AS supplier, o.total_amount FROM purchase_orders o JOIN suppliers sup ON sup.id = o.supplier_id
              WHERE o.status <> 'cancelled' ORDER BY o.id DESC LIMIT 500"
        );
        $scope = $this->user()->scopeFor('finance.invoice');
        $visible = array_column($this->db->fetchAll('SELECT id, site_id FROM purchase_orders'), 'site_id', 'id');

        return $this->render('finance/invoices', [
            'title' => t('finance.invoices'),
            'invoices' => $this->finance->invoices(['unpaid' => $request->string('unpaid') === '1', 'site_id' => $this->siteFilter($request)]),
            'orders' => array_values(array_filter($orders, static fn (array $o): bool => $scope->allows((int) ($visible[$o['id']] ?? 0)))),
            'orderId' => $request->int('order_id'),
            'unpaidOnly' => $request->string('unpaid') === '1',
            'canRecord' => $this->user()->can('finance.invoice'),
            'canRequest' => $this->user()->can('finance.request'),
        ]);
    }

    public function storeInvoice(Request $request): Response
    {
        $this->payments->recordInvoice($request->body, $request->file('invoice_file'));

        return $this->redirect('/finance/invoices', t('flash.created'));
    }

    public function cancelInvoice(Request $request, array $params): Response
    {
        $this->payments->cancelInvoice($this->id($params), (string) $request->string('cancel_reason'));

        return $this->redirect('/finance/invoices', t('flash.updated'));
    }

    // --- Décaissements ----------------------------------------------------------------

    public function payments(Request $request): Response
    {
        $status = $request->string('status');
        $status = in_array($status, FinanceRepository::STATUSES, true) ? $status : null;

        return $this->render('finance/payments', [
            'title' => t('finance.payments'),
            'payments' => $this->finance->payments(['status' => $status, 'site_id' => $this->siteFilter($request), 'to_justify' => $request->string('to_justify') === '1']),
            'status' => $status,
            'toJustify' => $request->string('to_justify') === '1',
            'canRequest' => $this->user()->can('finance.request'),
        ]);
    }

    public function createPayment(Request $request): Response
    {
        $user = $this->user();
        $kind = $request->string('kind');
        $kind = in_array($kind, FinanceRepository::KINDS, true) ? $kind : 'expense';
        $invoice = $request->int('invoice_id') !== null ? $this->finance->findInvoice((int) $request->int('invoice_id')) : null;
        if ($invoice !== null) {
            $kind = 'invoice';
        }
        $sites = array_values(array_filter($this->sites->listActive(), static fn (array $s): bool => $user->canAt('finance.request', (int) $s['id'])));

        return $this->render('finance/payment_form', [
            'title' => t('finance.payment_new'),
            'kind' => $kind,
            'invoice' => $invoice,
            'invoices' => array_values(array_filter($this->finance->invoices(['unpaid' => true]), static fn (array $i): bool => (float) $i['payable'] > 0)),
            'requests' => $this->db->fetchAll(
                "SELECT r.id, r.reference, r.subject, r.site_id FROM purchase_requests r WHERE r.status = 'approved' ORDER BY r.id DESC LIMIT 500"
            ),
            'requestId' => $request->int('request_id'),
            'sites' => $sites,
            'departments' => $this->references->options('departments'),
            'costCenters' => $this->references->options('cost-centers'),
            'projects' => $this->parts->beneficiaries()['projects'],
        ]);
    }

    public function storePayment(Request $request): Response
    {
        $id = $this->payments->request($request->body);

        return $this->redirect("/finance/payments/$id", t('flash.created'));
    }

    public function showPayment(Request $request, array $params): Response
    {
        $payment = $this->finance->findPayment($this->id($params)) ?? throw HttpException::notFound();
        $id = (int) $payment['id'];
        $user = $this->user();
        $siteId = (int) $payment['site_id'];
        $approval = $this->approvals->forEntity(PaymentService::ENTITY, $id);
        $actors = array_map('intval', array_column($approval['actions'] ?? [], 'acted_by'));
        $separated = (int) ($payment['requested_by'] ?? 0) !== $user->id && !in_array($user->id, $actors, true);

        return $this->render('finance/payment_show', [
            'title' => $payment['reference'],
            'payment' => $payment,
            'approval' => $approval,
            'attachments' => $this->attachments->list(PaymentService::ENTITY, $id, null, true),
            'invoiceFiles' => $payment['invoice_id'] !== null ? $this->attachments->list('supplier_invoice', (int) $payment['invoice_id']) : [],
            'methods' => $this->db->fetchAll('SELECT id, code, name FROM payment_methods WHERE is_active = 1 ORDER BY id'),
            'registers' => $this->finance->registers($siteId),
            'canPay' => $payment['status'] === 'approved' && $user->canAt('finance.pay', $siteId) && $separated,
            'payBlocked' => $payment['status'] === 'approved' && $user->canAt('finance.pay', $siteId) && !$separated,
            'canJustify' => $payment['kind'] === 'advance' && $payment['status'] === 'paid' && (float) $payment['to_justify'] > 0
                && ($user->canAt('finance.pay', $siteId) || $user->canAt('finance.invoice', $siteId)),
            'canCancel' => in_array($payment['status'], ['pending', 'approved'], true)
                && ((int) ($payment['requested_by'] ?? 0) === $user->id || $user->canAt('approvals.override', $siteId)),
        ]);
    }

    public function pay(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->payments->pay($id, $request->body, $request->file('proof'));

        return $this->redirect("/finance/payments/$id", t('flash.payment_recorded'));
    }

    public function justify(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->payments->justify($id, $request->body, $request->file('proof'));

        return $this->redirect("/finance/payments/$id", t('flash.updated'));
    }

    public function cancelPayment(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->payments->cancel($id, (string) $request->string('cancel_reason'));

        return $this->redirect("/finance/payments/$id", t('flash.updated'));
    }

    // --- Caisse -----------------------------------------------------------------------

    public function cashJournal(Request $request): Response
    {
        $user = $this->user();
        $registers = $this->finance->registers();
        $registerId = $request->int('register_id') ?? (isset($registers[0]) ? (int) $registers[0]['id'] : null);
        $register = $registerId !== null ? $this->finance->findRegister($registerId) : null;
        $date = (string) $request->string('date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) {
            $date = date('Y-m-d');
        }
        $sites = array_values(array_filter($this->sites->listActive(), static fn (array $s): bool => $user->canAt('cash.manage', (int) $s['id'])));

        return $this->render('finance/cash', [
            'title' => t('finance.cash'),
            'registers' => $registers,
            'register' => $register,
            'date' => $date,
            'journal' => $register !== null ? $this->finance->journal((int) $register['id'], $date) : null,
            'canManage' => $register !== null && $user->canAt('cash.manage', (int) $register['site_id']),
            'sites' => $sites,
        ]);
    }

    public function storeRegister(Request $request): Response
    {
        $id = $this->cash->createRegister($request->body);

        return $this->redirect('/finance/cash?register_id=' . $id, t('flash.created'));
    }

    public function cashEntry(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->cash->manualEntry($id, $request->body);

        return $this->redirect('/finance/cash?register_id=' . $id, t('flash.created'));
    }

    public function cashClose(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->cash->close($id, $request->body);

        return $this->redirect('/finance/cash?register_id=' . $id . '&date=' . rawurlencode((string) $request->string('closing_date')), t('flash.cash_closed'));
    }

    // --- États --------------------------------------------------------------------------

    public function reports(Request $request): Response
    {
        [$period, $from, $to] = self::period($request);
        $group = in_array($request->string('group'), FinanceRepository::GROUPS, true) ? (string) $request->string('group') : 'site';
        $siteId = $this->siteFilter($request);

        return $this->render('finance/reports', [
            'title' => t('finance.reports'),
            'period' => $period,
            'group' => $group,
            'totals' => $this->finance->spendTotals($from, $to, $siteId),
            'sites' => $this->sites->listActive(),
            'siteId' => $siteId,
        ]);
    }

    public function reportsData(Request $request): Response
    {
        [, $from, $to] = self::period($request);
        $group = in_array($request->string('group'), FinanceRepository::GROUPS, true) ? (string) $request->string('group') : 'site';
        $dt = DataTableRequest::fromQuery($request->query, FinanceRepository::SORTABLE, ['x.paid', 'DESC']);

        return $this->json($this->finance->spend($dt, $group, $from, $to, $this->siteFilter($request)));
    }

    public function reconciliation(Request $request): Response
    {
        return $this->render('finance/reconciliation', [
            'title' => t('finance.reconciliation'),
            'rows' => $this->finance->reconciliation($this->siteFilter($request), $request->string('anomalies') === '1'),
            'anomaliesOnly' => $request->string('anomalies') === '1',
        ]);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private static function period(Request $request): array
    {
        $period = (string) $request->string('period');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            $period = date('Y-m');
        }
        [$from, $to] = StockPeriodService::bounds($period);

        return [$period, $from, $to];
    }
}
