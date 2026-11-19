<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\FinanceRepository;
use App\Security\Auth;

/**
 * Caisse d'un site (cahier des charges § 7) : écritures numérotées jamais modifiées, solde jamais négatif,
 * arrêté quotidien avec soldes d'ouverture et de clôture et comptage physique. Un jour arrêté est verrouillé.
 */
final class CashService
{
    public function __construct(
        private readonly Database $db,
        private readonly FinanceRepository $finance,
        private readonly NumberingService $numbering,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    public function createRegister(array $input): int
    {
        $data = Validator::validate($input, ['site_id' => 'required|int', 'name' => 'required|string|max:80']);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        if (!$user->canAt('cash.manage', $data['site_id'])) {
            throw Validator::fail('site_id', 'exists');
        }
        if ($this->db->fetchValue('SELECT 1 FROM cash_registers WHERE site_id = :s AND name = :n', ['s' => $data['site_id'], 'n' => $data['name']])) {
            throw Validator::fail('name', 'unique');
        }

        return $this->db->transaction(function () use ($data): int {
            $id = $this->finance->createRegister($data['site_id'], $data['name']);
            $this->logger->log('create', 'cash_register', $id, $data['site_id'], null, $data, $data['name']);

            return $id;
        });
    }

    /** Écriture manuelle : approvisionnement de la caisse (entrée) ou sortie hors décaissement (remise en banque…). */
    public function manualEntry(int $registerId, array $input): int
    {
        $data = Validator::validate($input, [
            'direction' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0.01|max:100000000000',
            'label' => 'required|string|max:255',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $register = $this->finance->findRegister($registerId) ?? throw HttpException::notFound();
        if (!$user->canAt('cash.manage', (int) $register['site_id'])) {
            throw HttpException::forbidden();
        }

        return $this->entry($registerId, $data['direction'], $data['amount'], $data['label'], null);
    }

    /**
     * Passe une écriture datée du jour. Refusée si la journée est déjà arrêtée ou si la caisse n'a pas les fonds.
     *
     * @return int identifiant de l'écriture
     */
    public function entry(int $registerId, string $direction, float $amount, string $label, ?int $paymentRequestId): int
    {
        return $this->db->transaction(function () use ($registerId, $direction, $amount, $label, $paymentRequestId): int {
            $register = $this->db->fetchOne('SELECT * FROM cash_registers WHERE id = :id AND is_active = 1 FOR UPDATE', ['id' => $registerId]) ?? throw HttpException::notFound();
            $today = date('Y-m-d');
            if ($this->finance->isDayClosed($registerId, $today)) {
                throw Validator::fail('amount', 'cash_day_closed');
            }
            $balance = (float) $register['balance'];
            $after = round($direction === 'in' ? $balance + $amount : $balance - $amount, 2);
            if ($after < 0) {
                // Disponibilité financière : la caisse ne paie pas ce qu'elle n'a pas.
                throw Validator::fail('amount', 'cash_insufficient', ['available' => format_money($balance)]);
            }
            $reference = $this->numbering->next('PC');
            $id = $this->finance->addEntry([
                'register_id' => $registerId, 'entry_date' => $today, 'reference' => $reference, 'direction' => $direction, 'amount' => $amount,
                'balance_after' => $after, 'label' => mb_substr($label, 0, 255), 'payment_request_id' => $paymentRequestId, 'created_by' => $this->auth->user()?->id,
            ]);
            $this->logger->log('cash_' . $direction, 'cash_register', $registerId, (int) $register['site_id'], ['balance' => $balance],
                ['balance' => $after, 'amount' => $amount, 'label' => $label, 'reference' => $reference], $reference);

            return $id;
        });
    }

    /**
     * Arrêté d'une journée : solde théorique, solde compté, écart (à expliquer). Les jours se clôturent dans l'ordre.
     */
    public function close(int $registerId, array $input): void
    {
        $data = Validator::validate($input, [
            'closing_date' => 'required|date',
            'counted_balance' => 'required|numeric|min:0|max:100000000000',
            'gap_note' => 'string|max:255',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($registerId, $data, $user): void {
            $register = $this->finance->findRegister($registerId, true) ?? throw HttpException::notFound();
            if (!$user->canAt('cash.manage', (int) $register['site_id'])) {
                throw HttpException::forbidden();
            }
            if ($data['closing_date'] > date('Y-m-d')) {
                throw Validator::fail('closing_date', 'stock_future');
            }
            if ($this->finance->isDayClosed($registerId, $data['closing_date'])) {
                throw Validator::fail('closing_date', 'cash_day_closed');
            }
            $earlier = $this->finance->unclosedDayBefore($registerId, $data['closing_date']);
            if ($earlier !== null) {
                throw Validator::fail('closing_date', 'cash_previous_open', ['date' => format_date($earlier)]);
            }
            $journal = $this->finance->journal($registerId, $data['closing_date']);
            $gap = round($data['counted_balance'] - $journal['closing'], 2);
            if (abs($gap) > 0.005 && $data['gap_note'] === null) {
                throw Validator::fail('gap_note', 'cash_gap_note');
            }
            $this->finance->addClosing([
                'register_id' => $registerId, 'closing_date' => $data['closing_date'], 'opening_balance' => $journal['opening'],
                'total_in' => $journal['total_in'], 'total_out' => $journal['total_out'], 'closing_balance' => $journal['closing'],
                'counted_balance' => $data['counted_balance'], 'gap' => $gap, 'gap_note' => $data['gap_note'], 'closed_by' => $user->id,
            ]);
            $this->logger->log('cash_close', 'cash_register', $registerId, (int) $register['site_id'], null,
                ['date' => $data['closing_date'], 'opening' => $journal['opening'], 'closing' => $journal['closing'], 'counted' => $data['counted_balance'], 'gap' => $gap],
                $register['name'] . ' – ' . $data['closing_date']);
        });
    }
}
