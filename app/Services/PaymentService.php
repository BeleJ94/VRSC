<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\FinanceRepository;
use App\Security\Auth;

/**
 * Factures fournisseurs et décaissements (cahier des charges § 7).
 *
 * - Une facture (fournisseur + numéro) ne s'enregistre qu'une fois, toujours contre une commande, sans dépasser son montant.
 * - Un décaissement se rattache à une facture, à un EDB approuvé (avance à justifier) ou constitue une dépense directe
 *   dont l'approbation vaut engagement. Circuit : vérification Finance → approbation → paiement par un caissier.
 * - Le payeur n'est ni le demandeur ni un approbateur ; une preuve de paiement est obligatoire.
 * - Un paiement ne clôture jamais un EDB : seule la réception le fait.
 */
final class PaymentService
{
    public const ENTITY = 'payment_request';

    public function __construct(
        private readonly Database $db,
        private readonly FinanceRepository $finance,
        private readonly CashService $cash,
        private readonly ApprovalService $approvals,
        private readonly NumberingService $numbering,
        private readonly AttachmentService $attachments,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    // --- Factures -------------------------------------------------------------------

    /** @param array|null $file scan de la facture (entrée de $_FILES) */
    public function recordInvoice(array $input, ?array $file = null): int
    {
        $data = Validator::validate($input, [
            'order_id' => 'required|int',
            'invoice_number' => 'required|string|max:60',
            'invoice_date' => 'required|date',
            'due_date' => 'date',
            'amount' => 'required|numeric|min:0.01|max:100000000000',
            'note' => 'string|max:255',
        ]);
        $data['invoice_number'] = mb_strtoupper(preg_replace('/\s+/', '', $data['invoice_number']) ?? '');
        if ($data['due_date'] !== null && $data['due_date'] < $data['invoice_date']) {
            throw Validator::fail('due_date', 'period_order');
        }

        return $this->db->transaction(function () use ($data, $file): int {
            $order = $this->db->fetchOne('SELECT * FROM purchase_orders WHERE id = :id FOR UPDATE', ['id' => $data['order_id']]);
            if ($order === null || $order['status'] === 'cancelled') {
                throw Validator::fail('order_id', 'exists');
            }
            if (!($this->auth->user()?->canAt('finance.invoice', (int) $order['site_id']) ?? false)) {
                throw HttpException::forbidden();
            }
            // Contrôle d'identifiant : même fournisseur, même numéro = même facture.
            if ($this->finance->invoiceNumberExists((int) $order['supplier_id'], $data['invoice_number'])) {
                throw Validator::fail('invoice_number', 'invoice_duplicate');
            }
            $figures = $this->finance->orderFigures((int) $order['id']);
            if ((float) $figures['invoiced'] + $data['amount'] > (float) $figures['ordered'] + 0.005) {
                throw Validator::fail('amount', 'invoice_over_order', ['max' => format_money((float) $figures['ordered'] - (float) $figures['invoiced'])]);
            }
            $id = $this->finance->createInvoice($data + [
                'supplier_id' => (int) $order['supplier_id'], 'site_id' => (int) $order['site_id'], 'created_by' => $this->auth->user()?->id,
            ]);
            if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $this->attachments->add('supplier_invoice', $id, (int) $order['site_id'], $file, 'invoice');
            }
            $this->logger->log('create', 'supplier_invoice', $id, (int) $order['site_id'], null, $data, $data['invoice_number'] . ' – ' . $order['reference']);

            return $id;
        });
    }

    public function cancelInvoice(int $id, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw Validator::fail('cancel_reason', 'required');
        }
        $this->db->transaction(function () use ($id, $reason): void {
            $invoice = $this->finance->findInvoice($id, true) ?? throw HttpException::notFound();
            if ($invoice['status'] !== 'recorded' || (float) $invoice['paid'] > 0 || (float) $invoice['in_progress'] > 0) {
                throw Validator::fail('cancel_reason', 'invoice_not_cancellable');
            }
            $this->finance->cancelInvoice($id, mb_substr($reason, 0, 255));
            $this->logger->log('transition', 'supplier_invoice', $id, (int) $invoice['site_id'], ['status' => 'recorded'], ['status' => 'cancelled', 'reason' => $reason], $invoice['invoice_number']);
        });
    }

    // --- Demandes de décaissement -----------------------------------------------------

    public function request(array $input): int
    {
        $data = Validator::validate($input, [
            'kind' => 'required|in:' . implode(',', FinanceRepository::KINDS),
            'invoice_id' => 'int',
            'request_id' => 'int',
            'site_id' => 'int',
            'project_id' => 'int',
            'cost_center_id' => 'int',
            'department_id' => 'int',
            'beneficiary' => 'string|max:170',
            'category' => 'in:' . implode(',', FinanceRepository::CATEGORIES),
            'amount' => 'required|numeric|min:0.01|max:100000000000',
            'reason' => 'required|string|max:500',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');

        return $this->db->transaction(function () use ($data, $user): int {
            $row = match ($data['kind']) {
                'invoice' => $this->fromInvoice($data),
                'advance' => $this->fromRequest($data),
                default => $this->direct($data),
            };
            if (!$user->canAt('finance.request', (int) $row['site_id'])) {
                throw HttpException::forbidden();
            }
            $reference = $this->numbering->next('DD');
            $id = $this->finance->createPayment($row + [
                'reference' => $reference, 'kind' => $data['kind'], 'amount' => round($data['amount'], 2), 'reason' => $data['reason'], 'requested_by' => $user->id,
            ]);
            $this->logger->log('create', self::ENTITY, $id, (int) $row['site_id'], null,
                ['reference' => $reference, 'kind' => $data['kind'], 'amount' => $data['amount'], 'beneficiary' => $row['beneficiary']], $reference . ' – ' . $row['beneficiary']);
            $approval = $this->approvals->start('payment_request', self::ENTITY, $id, (int) $row['site_id'], round($data['amount'], 2), $reference);
            if ($approval['status'] === 'approved') {
                $this->onApproval($id, 'approved');
            }

            return $id;
        });
    }

    public function onApproval(int $id, string $status): void
    {
        $payment = $this->db->fetchOne('SELECT * FROM payment_requests WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($payment === null || $payment['status'] !== 'pending') {
            return;
        }
        $new = ['approved' => 'approved', 'rejected' => 'rejected', 'cancelled' => 'cancelled'][$status] ?? null;
        if ($new === null) {
            return;
        }
        $this->finance->updatePayment($id, ['status' => $new] + ($new === 'approved' ? ['approved_at' => date('Y-m-d H:i:s')] : []));
        $this->logger->log('transition', self::ENTITY, $id, (int) $payment['site_id'], ['status' => 'pending'], ['status' => $new], $payment['reference']);
    }

    /**
     * Paiement par la caisse ou par virement / chèque, avec sa preuve.
     *
     * @param array|null $proof preuve de paiement (reçu, ordre de virement…) — entrée de $_FILES, obligatoire
     */
    public function pay(int $id, array $input, ?array $proof): void
    {
        $data = Validator::validate($input, [
            'payment_method_id' => 'required|int',
            'payment_reference' => 'string|max:80',
            'cash_register_id' => 'int',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($id, $data, $user, $proof): void {
            $payment = $this->finance->findPayment($id, true) ?? throw HttpException::notFound();
            $siteId = (int) $payment['site_id'];
            if (!$user->canAt('finance.pay', $siteId)) {
                throw HttpException::forbidden();
            }
            if ($payment['status'] !== 'approved') {
                // Ni une demande ni une vérification ne valent ordre de payer.
                throw Validator::fail('status', 'payment_not_approved');
            }
            // Séparation des fonctions : le payeur n'est ni le demandeur ni l'un des approbateurs.
            $approvers = array_map('intval', array_column($this->db->fetchAll(
                "SELECT a.acted_by FROM approval_actions a JOIN approval_requests ar ON ar.id = a.request_id WHERE ar.entity_type = :t AND ar.entity_id = :e",
                ['t' => self::ENTITY, 'e' => $id]
            ), 'acted_by'));
            if ((int) ($payment['requested_by'] ?? 0) === $user->id || in_array($user->id, $approvers, true)) {
                throw Validator::fail('status', 'payment_same_actor');
            }
            $method = $this->db->fetchOne('SELECT * FROM payment_methods WHERE id = :id AND is_active = 1', ['id' => $data['payment_method_id']])
                ?? throw Validator::fail('payment_method_id', 'exists');
            $isCash = $method['code'] === 'CAISSE';
            if ($proof === null || ($proof['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw Validator::fail('attachment', 'attachment_required');
            }
            // Double paiement d'une même facture : le reste à payer est recontrôlé au moment de payer.
            if ($payment['invoice_id'] !== null) {
                $invoice = $this->db->fetchOne('SELECT * FROM supplier_invoices WHERE id = :id FOR UPDATE', ['id' => $payment['invoice_id']]);
                $paid = (float) $this->db->fetchValue("SELECT COALESCE(SUM(amount), 0) FROM payment_requests WHERE invoice_id = :i AND status = 'paid'", ['i' => $payment['invoice_id']]);
                if ($invoice === null || $invoice['status'] !== 'recorded' || $paid + (float) $payment['amount'] > (float) $invoice['amount'] + 0.005) {
                    throw Validator::fail('status', 'payment_duplicate');
                }
            }
            $fields = ['status' => 'paid', 'paid_at' => date('Y-m-d H:i:s'), 'paid_by' => $user->id, 'payment_method_id' => (int) $method['id']];
            if ($isCash) {
                $register = $data['cash_register_id'] !== null ? $this->finance->findRegister($data['cash_register_id']) : null;
                if ($register === null || (int) $register['site_id'] !== $siteId) {
                    throw Validator::fail('cash_register_id', 'exists');
                }
                $entryId = $this->cash->entry((int) $register['id'], 'out', (float) $payment['amount'], $payment['reference'] . ' – ' . $payment['beneficiary'], $id);
                $fields['cash_register_id'] = (int) $register['id'];
                $fields['payment_reference'] = (string) $this->db->fetchValue('SELECT reference FROM cash_entries WHERE id = :id', ['id' => $entryId]);
            } else {
                if ($data['payment_reference'] === null) {
                    throw Validator::fail('payment_reference', 'required');
                }
                // Une même référence de virement ou de chèque ne règle pas deux demandes.
                if ($this->finance->paymentReferenceUsed((int) $method['id'], $data['payment_reference'], $id)) {
                    throw Validator::fail('payment_reference', 'payment_reference_used');
                }
                $fields['payment_reference'] = $data['payment_reference'];
            }
            $this->attachments->add(self::ENTITY, $id, $siteId, $proof, 'payment_proof');
            $this->finance->updatePayment($id, $fields);
            $this->logger->log('payment', self::ENTITY, $id, $siteId, ['status' => 'approved'],
                ['status' => 'paid', 'method' => $method['name'], 'reference' => $fields['payment_reference'], 'amount' => $payment['amount']], $payment['reference']);
        });
    }

    /**
     * Justification d'une avance : pièces de dépense (montant justifié) et reliquat rendu à la caisse.
     *
     * @param array|null $file pièce justificative, obligatoire dès qu'un montant est justifié
     */
    public function justify(int $id, array $input, ?array $file): void
    {
        $data = Validator::validate($input, [
            'justified_amount' => 'numeric|min:0|max:100000000000',
            'returned_amount' => 'numeric|min:0|max:100000000000',
            'cash_register_id' => 'int',
        ]);
        $justified = (float) ($data['justified_amount'] ?? 0);
        $returned = (float) ($data['returned_amount'] ?? 0);
        if ($justified <= 0 && $returned <= 0) {
            throw Validator::fail('justified_amount', 'required');
        }
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($id, $data, $justified, $returned, $user, $file): void {
            $payment = $this->finance->findPayment($id, true) ?? throw HttpException::notFound();
            $siteId = (int) $payment['site_id'];
            if (!$user->canAt('finance.pay', $siteId) && !$user->canAt('finance.invoice', $siteId)) {
                throw HttpException::forbidden();
            }
            if ($payment['kind'] !== 'advance' || $payment['status'] !== 'paid') {
                throw Validator::fail('status', 'advance_not_open');
            }
            if ($justified + $returned > (float) $payment['to_justify'] + 0.005) {
                throw Validator::fail('justified_amount', 'advance_over', ['max' => format_money($payment['to_justify'])]);
            }
            if ($justified > 0) {
                if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    throw Validator::fail('attachment', 'attachment_required');
                }
                $this->attachments->add(self::ENTITY, $id, $siteId, $file, 'advance_proof');
            }
            if ($returned > 0) {
                // Le reliquat revient dans la caisse du site.
                $register = $data['cash_register_id'] !== null ? $this->finance->findRegister($data['cash_register_id']) : null;
                if ($register === null || (int) $register['site_id'] !== $siteId) {
                    throw Validator::fail('cash_register_id', 'exists');
                }
                $this->cash->entry((int) $register['id'], 'in', $returned, $payment['reference'] . ' – reliquat ' . $payment['beneficiary'], $id);
            }
            $newJustified = round((float) $payment['justified_amount'] + $justified, 2);
            $newReturned = round((float) $payment['returned_amount'] + $returned, 2);
            $done = $newJustified + $newReturned >= (float) $payment['amount'] - 0.005;
            $this->finance->updatePayment($id, ['justified_amount' => $newJustified, 'returned_amount' => $newReturned] + ($done ? ['justified_at' => date('Y-m-d H:i:s')] : []));
            $this->logger->log('advance_justify', self::ENTITY, $id, $siteId,
                ['justified_amount' => $payment['justified_amount'], 'returned_amount' => $payment['returned_amount']],
                ['justified_amount' => $newJustified, 'returned_amount' => $newReturned], $payment['reference']);
        });
    }

    public function cancel(int $id, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw Validator::fail('cancel_reason', 'required');
        }
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($id, $reason, $user): void {
            $payment = $this->finance->findPayment($id, true) ?? throw HttpException::notFound();
            if ((int) ($payment['requested_by'] ?? 0) !== $user->id && !$user->canAt('approvals.override', (int) $payment['site_id'])) {
                throw HttpException::forbidden();
            }
            if (!in_array($payment['status'], ['pending', 'approved'], true)) {
                throw Validator::fail('cancel_reason', 'payment_not_cancellable');
            }
            if ($payment['approval_id'] !== null) {
                $this->db->execute("UPDATE approval_requests SET status = 'cancelled', current_step_id = NULL, closed_at = NOW() WHERE id = :id", ['id' => $payment['approval_id']]);
            }
            $this->finance->updatePayment($id, ['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 500)]);
            $this->logger->log('transition', self::ENTITY, $id, (int) $payment['site_id'], ['status' => $payment['status']], ['status' => 'cancelled', 'reason' => $reason], $payment['reference']);
        });
    }

    // --- Rattachement de la demande ---------------------------------------------------------

    /** Règlement d'une facture : pas plus que ce qui reste à payer (déjà payé + demandes en cours déduits). */
    private function fromInvoice(array $data): array
    {
        $invoice = $data['invoice_id'] !== null ? $this->finance->findInvoice($data['invoice_id'], true) : null;
        if ($invoice === null || $invoice['status'] !== 'recorded') {
            throw Validator::fail('invoice_id', 'exists');
        }
        if ($data['amount'] > (float) $invoice['payable'] + 0.005) {
            throw Validator::fail('amount', 'payment_over_invoice', ['max' => format_money(max(0, (float) $invoice['payable']))]);
        }
        $edb = $this->db->fetchOne('SELECT * FROM purchase_requests WHERE id = :id', ['id' => $invoice['request_id']]) ?? [];

        return [
            'site_id' => (int) $invoice['site_id'], 'invoice_id' => (int) $invoice['id'], 'order_id' => (int) $invoice['order_id'],
            'request_id' => (int) $invoice['request_id'], 'supplier_id' => (int) $invoice['supplier_id'], 'beneficiary' => (string) $invoice['supplier'],
            'project_id' => $edb['project_id'] ?? null, 'cost_center_id' => $edb['cost_center_id'] ?? null, 'department_id' => $edb['department_id'] ?? null,
            'category' => $edb['category'] ?? 'other',
        ];
    }

    /** Avance à justifier : toujours sur un EDB approuvé (l'engagement existe déjà). */
    private function fromRequest(array $data): array
    {
        $edb = $data['request_id'] !== null ? $this->db->fetchOne('SELECT * FROM purchase_requests WHERE id = :id', ['id' => $data['request_id']]) : null;
        if ($edb === null || $edb['status'] !== 'approved') {
            throw Validator::fail('request_id', 'payment_needs_commitment');
        }
        if ($data['beneficiary'] === null) {
            throw Validator::fail('beneficiary', 'required');
        }

        return [
            'site_id' => (int) $edb['site_id'], 'request_id' => (int) $edb['id'], 'beneficiary' => $data['beneficiary'],
            'project_id' => $edb['project_id'], 'cost_center_id' => $edb['cost_center_id'], 'department_id' => $edb['department_id'], 'category' => $edb['category'],
        ];
    }

    /** Dépense directe : imputée à un site, son approbation vaut engagement. */
    private function direct(array $data): array
    {
        if ($data['site_id'] === null || !$this->db->fetchValue('SELECT 1 FROM sites WHERE id = :id AND is_active = 1', ['id' => $data['site_id']])) {
            throw Validator::fail('site_id', 'required');
        }
        if ($data['beneficiary'] === null) {
            throw Validator::fail('beneficiary', 'required');
        }
        foreach (['project_id' => ['projects', true], 'cost_center_id' => ['cost_centers', false], 'department_id' => ['departments', false]] as $field => [$table, $siteScoped]) {
            if ($data[$field] !== null && !$this->db->fetchValue(
                "SELECT 1 FROM $table WHERE id = :id" . ($siteScoped ? ' AND site_id = :s' : ''),
                ['id' => $data[$field]] + ($siteScoped ? ['s' => $data['site_id']] : [])
            )) {
                throw Validator::fail($field, 'exists');
            }
        }

        return [
            'site_id' => $data['site_id'], 'beneficiary' => $data['beneficiary'], 'project_id' => $data['project_id'],
            'cost_center_id' => $data['cost_center_id'], 'department_id' => $data['department_id'], 'category' => $data['category'] ?? 'other',
        ];
    }
}
