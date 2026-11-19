<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\EquipmentCostRepository;
use App\Security\Auth;

/**
 * Carburant et coût mensuel par équipement (cahier des charges § 8, recette R8).
 *
 * - Ravitaillement : depuis la cuve du site (sortie de stock valorisée au coût moyen — c'est elle qui porte le coût,
 *   le ravitaillement ne le compte pas une seconde fois) ou en station (prix saisi, sinon coût « manquant »).
 * - Autres charges et prestations externes saisies avec leur référence.
 * - Validation mensuelle par site : le rapport est figé, plus rien ne se date dans la période.
 */
final class EquipmentCostService
{
    /** Au-delà, l'écart de compteur depuis le dernier index est signalé comme incohérent. */
    public const MAX_HOURS_PER_DAY = 24;
    public const MAX_KM_PER_DAY = 1000;

    public function __construct(
        private readonly Database $db,
        private readonly EquipmentCostRepository $costs,
        private readonly StockService $stock,
        private readonly NumberingService $numbering,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /** @return int identifiant du ravitaillement */
    public function refill(array $input): int
    {
        $data = Validator::validate($input, [
            'equipment_id' => 'required|int',
            'refill_date' => 'date',
            'hour_meter' => 'int|min:0|max:999999',
            'odometer_km' => 'int|min:0|max:9999999',
            'litres' => 'required|numeric|min:0.1|max:100000',
            'source' => 'required|in:stock,external',
            'part_id' => 'int',
            'station' => 'string|max:120',
            'unit_price' => 'numeric|min:0|max:1000000',
            'voucher_ref' => 'string|max:60',
            'note' => 'string|max:255',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $data['refill_date'] ??= date('Y-m-d');
        if ($data['refill_date'] > date('Y-m-d')) {
            throw Validator::fail('refill_date', 'stock_future');
        }

        return $this->db->transaction(function () use ($data, $user): int {
            $equipment = $this->db->fetchOne(
                "SELECT e.*, COALESCE(e.meter_basis, c.meter_basis, 'hours') AS basis FROM equipment e LEFT JOIN equipment_categories c ON c.id = e.category_id
                  WHERE e.id = :id AND e.is_archived = 0 FOR UPDATE",
                ['id' => $data['equipment_id']]
            ) ?? throw Validator::fail('equipment_id', 'exists');
            $siteId = (int) $equipment['site_id'];
            if (!$user->canAt('fuel.record', $siteId)) {
                throw HttpException::forbidden();
            }
            $this->assertOpen($siteId, $data['refill_date']);
            // Le compteur de suivi de l'engin est obligatoire : sans lui, pas de consommation calculable.
            $tracked = $equipment['basis'] === 'km' ? 'odometer_km' : 'hour_meter';
            if ($data[$tracked] === null) {
                throw Validator::fail($tracked, 'required');
            }
            $alerts = [];
            $lastReading = $this->db->fetchValue('SELECT MAX(recorded_at) FROM meter_readings WHERE equipment_id = :e', ['e' => $equipment['id']]) ?? $equipment['meter_updated_at'];
            foreach (['hour_meter' => self::MAX_HOURS_PER_DAY, 'odometer_km' => self::MAX_KM_PER_DAY] as $meter => $maxPerDay) {
                if ($data[$meter] === null || $equipment[$meter] === null) {
                    continue;
                }
                $delta = $data[$meter] - (int) $equipment[$meter];
                if ($delta < 0) {
                    // Compteur incohérent : un index ne recule pas.
                    throw Validator::fail($meter, 'meter_decrease', ['current' => format_number($equipment[$meter])]);
                }
                if ($lastReading === null) {
                    continue;
                }
                $days = max(1, (int) ((strtotime($data['refill_date']) - strtotime(substr((string) $lastReading, 0, 10))) / 86400));
                if ($delta > $maxPerDay * $days) {
                    $alerts[] = t('fuel.meter_jump', ['delta' => format_number($delta), 'unit' => $meter === 'hour_meter' ? 'h' : 'km', 'days' => $days]);
                }
            }
            $reference = $this->numbering->next('RAV');
            $row = [
                'reference' => $reference, 'equipment_id' => (int) $equipment['id'], 'site_id' => $siteId, 'refill_date' => $data['refill_date'],
                'hour_meter' => $data['hour_meter'], 'odometer_km' => $data['odometer_km'], 'litres' => round($data['litres'], 2), 'source' => $data['source'],
                'voucher_ref' => $data['voucher_ref'], 'note' => $data['note'], 'recorded_by' => $user->id,
                'meter_alert' => $alerts !== [] ? mb_substr(implode(' ; ', $alerts), 0, 255) : null,
            ];
            if ($data['source'] === 'stock') {
                $part = $data['part_id'] !== null ? $this->db->fetchOne("SELECT * FROM parts WHERE id = :id AND part_type = 'fuel' AND is_active = 1", ['id' => $data['part_id']]) : null;
                if ($part === null) {
                    throw Validator::fail('part_id', 'fuel_part');
                }
                // La sortie de stock porte le coût (coût moyen pondéré de la cuve) : une seule imputation.
                $this->stock->move((int) $part['id'], $siteId, 'out', $row['litres'], $reference . ' – ' . $equipment['code'], null, (int) $equipment['id'],
                    ['document_ref' => $reference, 'moved_on' => $data['refill_date']]);
                $row['part_id'] = (int) $part['id'];
                $row['stock_movement_id'] = (int) $this->db->fetchValue('SELECT MAX(id) FROM stock_movements WHERE document_ref = :r AND part_id = :p', ['r' => $reference, 'p' => $part['id']]);
            } else {
                $row['station'] = $data['station'];
                $row['unit_price'] = $data['unit_price']; // NULL = coût manquant, signalé dans le rapport
            }
            $id = $this->costs->createRefill($row);
            $this->db->execute(
                'UPDATE equipment SET hour_meter = COALESCE(:h, hour_meter), odometer_km = COALESCE(:k, odometer_km), meter_updated_at = NOW() WHERE id = :id',
                ['h' => $data['hour_meter'], 'k' => $data['odometer_km'], 'id' => $equipment['id']]
            );
            $this->db->execute(
                'INSERT INTO meter_readings (equipment_id, hour_meter, odometer_km, note, recorded_by, recorded_at) VALUES (:e, :h, :k, :n, :u, :at)',
                ['e' => $equipment['id'], 'h' => $data['hour_meter'], 'k' => $data['odometer_km'], 'n' => $reference, 'u' => $user->id,
                    'at' => $data['refill_date'] === date('Y-m-d') ? date('Y-m-d H:i:s') : $data['refill_date'] . ' 12:00:00']
            );
            $this->logger->log('fuel_refill', 'equipment', (int) $equipment['id'], $siteId, null,
                ['reference' => $reference, 'litres' => $row['litres'], 'source' => $data['source'], 'hour_meter' => $data['hour_meter'], 'odometer_km' => $data['odometer_km']],
                $reference . ' – ' . $equipment['code']);

            return $id;
        });
    }

    /** Autre charge imputable à un engin (assurance, taxe, location…). */
    public function addCharge(array $input): int
    {
        $data = Validator::validate($input, [
            'equipment_id' => 'required|int',
            'charge_date' => 'required|date',
            'label' => 'required|string|max:190',
            'amount' => 'required|numeric|min:0.01|max:100000000000',
            'document_ref' => 'string|max:60',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $equipment = $this->db->fetchOne('SELECT id, code, site_id FROM equipment WHERE id = :id', ['id' => $data['equipment_id']]) ?? throw Validator::fail('equipment_id', 'exists');
        $siteId = (int) $equipment['site_id'];
        if (!$user->canAt('costs.charge', $siteId)) {
            throw HttpException::forbidden();
        }
        if ($data['charge_date'] > date('Y-m-d')) {
            throw Validator::fail('charge_date', 'stock_future');
        }
        $this->assertOpen($siteId, $data['charge_date']);

        return $this->db->transaction(function () use ($data, $siteId, $user, $equipment): int {
            $id = $this->db->insert(
                'INSERT INTO equipment_charges (equipment_id, site_id, charge_date, label, amount, document_ref, created_by) VALUES (:e, :s, :d, :l, :a, :r, :u)',
                ['e' => $data['equipment_id'], 's' => $siteId, 'd' => $data['charge_date'], 'l' => $data['label'], 'a' => $data['amount'], 'r' => $data['document_ref'], 'u' => $user->id]
            );
            $this->logger->log('cost_charge', 'equipment', (int) $equipment['id'], $siteId, null, $data, $equipment['code'] . ' : ' . $data['label']);

            return $id;
        });
    }

    /** Observation du rapport mensuel d'un engin (tant que la période n'est pas close). */
    public function saveNote(int $equipmentId, array $input): void
    {
        $data = Validator::validate($input, ['period' => 'required|string|max:7', 'note' => 'required|string|max:500']);
        StockPeriodService::bounds($data['period']);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $equipment = $this->db->fetchOne('SELECT id, site_id FROM equipment WHERE id = :id', ['id' => $equipmentId]) ?? throw HttpException::notFound();
        if (!$user->canAt('costs.charge', (int) $equipment['site_id'])) {
            throw HttpException::forbidden();
        }
        if ($this->costs->isClosed((int) $equipment['site_id'], $data['period'])) {
            throw Validator::fail('period', 'cost_period_closed', ['period' => $data['period']]);
        }
        $this->costs->saveNote($equipmentId, $data['period'], $data['note'], $user->id);
    }

    /** Validation et clôture mensuelle des coûts d'un site : le rapport est figé et tracé. */
    public function close(array $input): int
    {
        $data = Validator::validate($input, ['site_id' => 'required|int', 'period' => 'required|string|max:7', 'note' => 'string|max:500']);
        StockPeriodService::bounds($data['period']);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        if (!$user->canAt('costs.close', $data['site_id'])) {
            throw HttpException::forbidden();
        }
        if ($data['period'] >= date('Y-m')) {
            throw Validator::fail('period', 'period_not_ended');
        }

        return $this->db->transaction(function () use ($data, $user): int {
            if ($this->costs->isClosed($data['site_id'], $data['period'])) {
                throw Validator::fail('period', 'period_already_closed');
            }
            $count = $this->costs->close($data['site_id'], $data['period'], $data['note'], $user->id);
            $missing = (int) $this->db->fetchValue('SELECT COALESCE(SUM(missing_count), 0) FROM equipment_cost_snapshots WHERE site_id = :s AND period = :p', ['s' => $data['site_id'], 'p' => $data['period']]);
            $this->logger->log('cost_close', 'cost_period', null, $data['site_id'], null,
                ['period' => $data['period'], 'equipment' => $count, 'missing' => $missing, 'note' => $data['note']], $data['period']);

            return $count;
        });
    }

    /** Aucune saisie datée dans une période dont les coûts sont validés. */
    public function assertOpen(int $siteId, string $date): void
    {
        if ($this->costs->isClosed($siteId, substr($date, 0, 7))) {
            throw Validator::fail('period', 'cost_period_closed', ['period' => substr($date, 0, 7)]);
        }
    }
}
