<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\FleetTransferRepository;
use App\Security\Auth;

/**
 * Transfert d'un engin entre sites (cahier des charges § 4, recette R3 et R4).
 *
 * Demande motivée → avis hiérarchique → contrôle technique (check-list) → autorisation Direction Logistique
 * (ordre de transfert OTV) → remise au conducteur et départ confirmé → réception contradictoire → changement de site.
 *
 * - Une demande n'autorise pas le départ.
 * - Un engin déclaré « inapte » ne part pas avant un nouveau contrôle favorable ; tous les contrôles sont conservés.
 * - L'affectation de l'engin ne change qu'à la réception confirmée à destination.
 */
final class FleetTransferService
{
    public const ENTITY = 'fleet_transfer';

    /** Points de la check-list technique, dans l'ordre du document. */
    public const ITEMS = ['engine', 'brakes', 'steering', 'tyres', 'lighting', 'hydraulics', 'fluids', 'batteries', 'safety', 'body', 'accessories', 'documents'];
    public const RESULTS = ['ok', 'reserve', 'nok', 'na'];
    public const CONCLUSIONS = ['fit', 'conditional', 'unfit'];
    public const MODES = ['own_power', 'low_loader'];

    public function __construct(
        private readonly Database $db,
        private readonly FleetTransferRepository $transfers,
        private readonly ApprovalService $approvals,
        private readonly ApprovalWorkflow $workflow,
        private readonly NumberingService $numbering,
        private readonly AttachmentService $attachments,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /** Demande motivée, émise par le site d'origine ou par le site de destination. */
    public function request(array $input): int
    {
        $data = Validator::validate($input, [
            'equipment_id' => 'required|int',
            'to_site_id' => 'required|int',
            'reason' => 'required|string|max:500',
            'planned_departure' => 'datetime',
            'planned_arrival' => 'datetime',
            'transport_mode' => 'required|in:' . implode(',', self::MODES),
            'carrier_id' => 'int',
            'tractor_plate' => 'string|max:30',
            'trailer_plate' => 'string|max:30',
            'transport_conditions' => 'string|max:500',
            'driver_id' => 'int',
            'driver_name' => 'string|max:170',
            'driver_phone' => 'string|max:30',
            'convoy_leader' => 'string|max:170',
            'observations' => 'string|max:1000',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $equipment = $this->db->fetchOne('SELECT id, code, site_id, is_archived FROM equipment WHERE id = :id', ['id' => $data['equipment_id']]);
        if ($equipment === null || (int) $equipment['is_archived'] === 1) {
            throw Validator::fail('equipment_id', 'exists');
        }
        $from = (int) $equipment['site_id'];
        if ($data['to_site_id'] === $from) {
            throw Validator::fail('to_site_id', 'transfer_same_site');
        }
        if (!$this->db->fetchValue('SELECT 1 FROM sites WHERE id = :id AND is_active = 1', ['id' => $data['to_site_id']])) {
            throw Validator::fail('to_site_id', 'exists');
        }
        if (!$user->canAt('fleet.transfer', $from) && !$user->canAt('fleet.transfer', $data['to_site_id'])) {
            throw HttpException::forbidden();
        }
        if ($this->transfers->activeFor($data['equipment_id']) !== null) {
            throw Validator::fail('equipment_id', 'fleet_transfer_active');
        }
        if ($data['planned_departure'] !== null && $data['planned_arrival'] !== null && $data['planned_arrival'] < $data['planned_departure']) {
            throw Validator::fail('planned_arrival', 'period_order');
        }
        // Les conditions d'acheminement sur porte-char sont documentées.
        if ($data['transport_mode'] === 'low_loader' && $data['transport_conditions'] === null) {
            throw Validator::fail('transport_conditions', 'fleet_low_loader');
        }
        if ($data['carrier_id'] !== null && !$this->db->fetchValue('SELECT 1 FROM carriers WHERE id = :id', ['id' => $data['carrier_id']])) {
            throw Validator::fail('carrier_id', 'exists');
        }
        $data = $this->resolveDriver($data, $from);

        $documents = array_map(
            static fn (array $d): string => $d['type'] . ($d['reference'] ? ' n° ' . $d['reference'] : '') . ($d['expires_at'] ? ' (' . $d['expires_at'] . ')' : ''),
            $this->transfers->onBoardDocuments($data['equipment_id'])
        );

        return $this->db->transaction(function () use ($data, $from, $user, $documents, $equipment): int {
            $reference = $this->numbering->next('OTV');
            $id = $this->transfers->create($data + [
                'reference' => $reference,
                'from_site_id' => $from,
                'documents_refs' => $documents !== [] ? mb_substr(implode(' ; ', $documents), 0, 1000) : null,
                'requested_by' => $user->id,
            ]);
            $this->logger->log('create', self::ENTITY, $id, $from, null, ['reference' => $reference, 'equipment' => $equipment['code'], 'to_site_id' => $data['to_site_id'], 'reason' => $data['reason']], $reference . ' – ' . $equipment['code']);
            // Le circuit est rattaché au site d'origine : c'est là que se trouvent l'engin et son contrôleur.
            $this->approvals->start('fleet_transfer', self::ENTITY, $id, $from, null, $reference, true);

            return $id;
        });
    }

    /**
     * Check-list technique préalable. results[point] = ok|reserve|nok|na, notes[point], conclusion, remarks, compteurs.
     * Conclusion « apte » ou « apte sous conditions » : le contrôle technique du circuit est validé.
     * Conclusion « inapte » : le contrôle est enregistré, l'engin reste bloqué jusqu'à un nouveau contrôle.
     *
     * @param list<array> $photos fichiers image (entrées de $_FILES)
     * @return int identifiant du contrôle
     */
    public function recordCheck(int $id, array $input, array $photos = []): int
    {
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $data = Validator::validate($input, [
            'conclusion' => 'required|in:' . implode(',', self::CONCLUSIONS),
            'remarks' => 'string|max:1000',
            'hour_meter' => 'int|min:0|max:999999',
            'odometer_km' => 'int|min:0|max:9999999',
            'results' => 'required|array',
            'notes' => 'array',
        ]);

        return $this->db->transaction(function () use ($id, $data, $user, $photos): int {
            $transfer = $this->transfers->find($id, true) ?? throw HttpException::notFound();
            $from = (int) $transfer['from_site_id'];
            if (!$user->canAt('fleet.check', $from)) {
                throw HttpException::forbidden();
            }
            $awaitingControl = $transfer['status'] === 'requested' && $transfer['approval_step'] === 'control';
            if (!$awaitingControl && $transfer['status'] !== 'authorized') {
                // Avant l'avis hiérarchique, ou une fois l'engin parti, il n'y a pas de contrôle préalable à saisir.
                throw Validator::fail('conclusion', 'fleet_check_not_expected');
            }
            $items = $this->items($data['results'], $data['notes']);
            $worst = array_reduce($items, static fn (int $carry, array $i): int => max($carry, ['na' => 0, 'ok' => 0, 'reserve' => 1, 'nok' => 2][$i['result']]), 0);
            // Cohérence : un point non conforme interdit « apte » ; une réserve impose au moins « apte sous conditions ».
            if ($data['conclusion'] === 'fit' && $worst > 0) {
                throw Validator::fail('conclusion', 'fleet_check_conclusion');
            }
            if ($data['conclusion'] !== 'fit' && $data['remarks'] === null) {
                throw Validator::fail('remarks', 'fleet_check_remarks');
            }
            if ($data['hour_meter'] === null && $data['odometer_km'] === null) {
                throw Validator::fail('hour_meter', 'index_required');
            }
            $checkId = $this->transfers->addCheck([
                'transfer_id' => $id, 'kind' => 'pre_departure', 'conclusion' => $data['conclusion'], 'remarks' => $data['remarks'],
                'hour_meter' => $data['hour_meter'], 'odometer_km' => $data['odometer_km'], 'checked_by' => $user->id, 'checker_name' => $user->name(),
            ], $items);
            foreach ($photos as $photo) {
                $this->attachments->add('fleet_transfer_check', $checkId, null, $photo, 'photo');
            }
            $this->logger->log('fleet_check', self::ENTITY, $id, $from, null,
                ['conclusion' => $data['conclusion'], 'remarks' => $data['remarks'], 'check_id' => $checkId], $transfer['reference']);
            if ($awaitingControl && $data['conclusion'] !== 'unfit') {
                $this->workflow->act((int) $transfer['approval_id'], [
                    'decision' => ApprovalService::FAVORABLE,
                    'comment' => t('fleet_conclusion.' . $data['conclusion']) . ($data['remarks'] !== null ? ' — ' . $data['remarks'] : ''),
                ], true);
            }

            return $checkId;
        });
    }

    /** Issue du circuit : autorisation de la Direction Logistique (l'OTV devient exécutoire), refus ou annulation. */
    public function onApproval(int $id, string $status): void
    {
        $transfer = $this->db->fetchOne('SELECT * FROM fleet_transfers WHERE id = :id FOR UPDATE', ['id' => $id]);
        if ($transfer === null || $transfer['status'] !== 'requested') {
            return;
        }
        $new = ['approved' => 'authorized', 'rejected' => 'rejected', 'cancelled' => 'cancelled'][$status] ?? null;
        if ($new === null) {
            return;
        }
        $this->transfers->update($id, ['status' => $new] + ($new === 'authorized' ? ['authorized_at' => date('Y-m-d H:i:s')] : []));
        $this->logger->log('transition', self::ENTITY, $id, (int) $transfer['from_site_id'], ['status' => 'requested'], ['status' => $new], $transfer['reference']);
    }

    /**
     * Remise au conducteur / transporteur et départ confirmé. Exige l'autorisation ET un dernier contrôle non « inapte ».
     */
    public function depart(int $id, array $input): void
    {
        $data = Validator::validate($input, [
            'departure_hours' => 'int|min:0|max:999999',
            'departure_km' => 'int|min:0|max:9999999',
            'departure_fuel' => 'int|min:0|max:100',
            'handover_to' => 'required|string|max:170',
            'driver_id' => 'int',
            'driver_name' => 'string|max:170',
            'driver_phone' => 'string|max:30',
            'convoy_leader' => 'string|max:170',
            'tractor_plate' => 'string|max:30',
            'trailer_plate' => 'string|max:30',
            'observations' => 'string|max:1000',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($id, $data, $user): void {
            $transfer = $this->transfers->find($id, true) ?? throw HttpException::notFound();
            $from = (int) $transfer['from_site_id'];
            if (!$user->canAt('fleet.dispatch', $from)) {
                throw HttpException::forbidden();
            }
            if ($transfer['status'] !== 'authorized') {
                // Une demande, un avis ou un contrôle ne valent pas autorisation de départ.
                throw Validator::fail('status', 'fleet_not_authorized');
            }
            $check = $this->transfers->latestCheck($id, 'pre_departure');
            if ($check === null || $check['conclusion'] === 'unfit') {
                throw Validator::fail('status', 'fleet_unfit');
            }
            if ($data['departure_hours'] === null && $data['departure_km'] === null) {
                throw Validator::fail('departure_hours', 'index_required');
            }
            foreach (['departure_hours' => 'equipment_hours', 'departure_km' => 'equipment_km'] as $field => $current) {
                if ($data[$field] !== null && $transfer[$current] !== null && $data[$field] < (int) $transfer[$current]) {
                    throw Validator::fail($field, 'meter_decrease', ['current' => format_number($transfer[$current])]);
                }
            }
            $driver = $this->resolveDriver(array_intersect_key($data, array_flip(['driver_id', 'driver_name', 'driver_phone'])), $from);
            $fields = ['status' => 'departed', 'departed_at' => date('Y-m-d H:i:s'), 'departed_by' => $user->id,
                'departure_hours' => $data['departure_hours'], 'departure_km' => $data['departure_km'], 'departure_fuel' => $data['departure_fuel'],
                'handover_to' => $data['handover_to']];
            foreach (['convoy_leader', 'tractor_plate', 'trailer_plate', 'observations'] as $optional) {
                if ($data[$optional] !== null) {
                    $fields[$optional] = $data[$optional];
                }
            }
            if ($driver['driver_name'] !== null) {
                $fields += $driver;
            }
            $this->transfers->update($id, $fields);
            $this->recordMeters((int) $transfer['equipment_id'], $data['departure_hours'], $data['departure_km'], $data['departure_fuel'], $transfer['reference'] . ' – départ', $user->id);
            $this->logger->log('transition', self::ENTITY, $id, $from, ['status' => 'authorized'],
                ['status' => 'departed', 'handover_to' => $data['handover_to'], 'hours' => $data['departure_hours'], 'km' => $data['departure_km']], $transfer['reference']);
        });
    }

    /**
     * Réception contradictoire à destination : procès-verbal (points, compteurs, état, écarts, photos).
     * C'est seulement ici que l'engin change de site.
     *
     * @param list<array> $photos
     */
    public function receive(int $id, array $input, array $photos = []): void
    {
        $data = Validator::validate($input, [
            'arrival_hours' => 'int|min:0|max:999999',
            'arrival_km' => 'int|min:0|max:9999999',
            'arrival_fuel' => 'int|min:0|max:100',
            'arrival_note' => 'string|max:1000',
            'results' => 'required|array',
            'notes' => 'array',
        ]);
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($id, $data, $user, $photos): void {
            $transfer = $this->transfers->find($id, true) ?? throw HttpException::notFound();
            $to = (int) $transfer['to_site_id'];
            $equipmentId = (int) $transfer['equipment_id'];
            if (!$user->canAt('fleet.dispatch', $to)) {
                throw HttpException::forbidden();
            }
            if ($transfer['status'] !== 'departed') {
                throw Validator::fail('status', 'fleet_not_departed');
            }
            // Réception contradictoire : par une autre personne que celle qui a confirmé le départ.
            if ((int) ($transfer['departed_by'] ?? 0) === $user->id) {
                throw Validator::fail('status', 'transfer_same_actor');
            }
            if ($data['arrival_hours'] === null && $data['arrival_km'] === null) {
                throw Validator::fail('arrival_hours', 'index_required');
            }
            foreach (['arrival_hours' => 'departure_hours', 'arrival_km' => 'departure_km'] as $field => $departure) {
                if ($data[$field] !== null && $transfer[$departure] !== null && $data[$field] < (int) $transfer[$departure]) {
                    throw Validator::fail($field, 'meter_decrease', ['current' => format_number($transfer[$departure])]);
                }
            }
            $items = $this->items($data['results'], $data['notes']);
            // Écart : un point constaté moins bon qu'au contrôle de départ.
            $rank = ['na' => 0, 'ok' => 0, 'reserve' => 1, 'nok' => 2];
            $before = $this->transfers->latestCheck($id, 'pre_departure')['items'] ?? [];
            $worse = [];
            foreach ($items as $item => $entry) {
                if ($rank[$entry['result']] > $rank[$before[$item]['result'] ?? 'ok']) {
                    $worse[] = $item;
                }
            }
            $discrepancy = $worse !== [];
            if ($discrepancy && $data['arrival_note'] === null) {
                throw Validator::fail('arrival_note', 'fleet_discrepancy_note');
            }
            $checkId = $this->transfers->addCheck([
                'transfer_id' => $id, 'kind' => 'arrival', 'conclusion' => null, 'remarks' => $data['arrival_note'],
                'hour_meter' => $data['arrival_hours'], 'odometer_km' => $data['arrival_km'], 'checked_by' => $user->id, 'checker_name' => $user->name(),
            ], $items);
            foreach ($photos as $photo) {
                $this->attachments->add('fleet_transfer_check', $checkId, null, $photo, 'photo');
            }
            $now = date('Y-m-d H:i:s');
            $this->transfers->update($id, [
                'status' => 'received', 'received_at' => $now, 'received_by' => $user->id,
                'arrival_hours' => $data['arrival_hours'], 'arrival_km' => $data['arrival_km'], 'arrival_fuel' => $data['arrival_fuel'],
                'has_discrepancy' => $discrepancy ? 1 : 0, 'arrival_note' => $data['arrival_note'],
            ]);
            $this->recordMeters($equipmentId, $data['arrival_hours'], $data['arrival_km'], $data['arrival_fuel'], $transfer['reference'] . ' – arrivée', $user->id);

            // Changement d'affectation : l'historique reste lié à l'engin, ventilé par site dans le temps.
            $open = $this->db->fetchOne('SELECT id FROM equipment_site_history WHERE equipment_id = :e AND ended_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE', ['e' => $equipmentId]);
            if ($open !== null) {
                $this->db->execute('UPDATE equipment_site_history SET ended_at = :now WHERE id = :id', ['now' => $now, 'id' => $open['id']]);
            } else {
                $this->db->execute(
                    'INSERT INTO equipment_site_history (equipment_id, site_id, started_at, ended_at) SELECT id, :s, created_at, :now FROM equipment WHERE id = :e',
                    ['s' => $transfer['from_site_id'], 'now' => $now, 'e' => $equipmentId]
                );
            }
            $this->db->execute(
                'INSERT INTO equipment_site_history (equipment_id, site_id, started_at, transfer_id) VALUES (:e, :s, :now, :t)',
                ['e' => $equipmentId, 's' => $to, 'now' => $now, 't' => $id]
            );
            $this->db->execute('UPDATE equipment SET site_id = :s, project_id = NULL WHERE id = :e', ['s' => $to, 'e' => $equipmentId]);
            // Les conducteurs du site d'origine ne sont plus affectés ; les plans préventifs suivent l'engin.
            $this->db->execute('UPDATE equipment_assignments SET ended_at = :now WHERE equipment_id = :e AND ended_at IS NULL', ['now' => $now, 'e' => $equipmentId]);
            $this->db->execute('UPDATE preventive_plans SET site_id = :s WHERE equipment_id = :e', ['s' => $to, 'e' => $equipmentId]);

            $this->logger->log('transition', self::ENTITY, $id, $to, ['status' => 'departed'],
                ['status' => 'received', 'discrepancy' => $discrepancy ? implode(', ', $worse) : null, 'note' => $data['arrival_note']], $transfer['reference']);
            $this->logger->log('site_change', 'equipment', $equipmentId, $to, ['site_id' => (int) $transfer['from_site_id']], ['site_id' => $to], $transfer['equipment_code'] . ' : ' . $transfer['from_site'] . ' → ' . $transfer['to_site']);
        });
    }

    /** Annulation motivée avant le départ (demandeur ou compte habilité aux dérogations). */
    public function cancel(int $id, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw Validator::fail('cancel_reason', 'required');
        }
        $user = $this->auth->user() ?? throw new HttpException(401, 'errors.unauthenticated');
        $this->db->transaction(function () use ($id, $reason, $user): void {
            $transfer = $this->transfers->find($id, true) ?? throw HttpException::notFound();
            $own = (int) ($transfer['requested_by'] ?? 0) === $user->id;
            if (!$own && !$user->canAt('approvals.override', (int) $transfer['from_site_id']) && !$user->canAt('approvals.override', (int) $transfer['to_site_id'])) {
                throw HttpException::forbidden();
            }
            if (!in_array($transfer['status'], ['requested', 'authorized'], true)) {
                throw Validator::fail('status', 'transfer_not_cancellable');
            }
            if ($transfer['approval_id'] !== null) {
                $this->db->execute("UPDATE approval_requests SET status = 'cancelled', current_step_id = NULL, closed_at = NOW() WHERE id = :id", ['id' => $transfer['approval_id']]);
            }
            $this->transfers->update($id, ['status' => 'cancelled', 'cancel_reason' => mb_substr($reason, 0, 500)]);
            $this->logger->log('transition', self::ENTITY, $id, (int) $transfer['from_site_id'], ['status' => $transfer['status']],
                ['status' => 'cancelled', 'reason' => mb_substr($reason, 0, 255)], $transfer['reference']);
        });
    }

    /**
     * Points de contrôle complets et valides.
     *
     * @return array<string, array{result: string, note: ?string}>
     */
    private function items(array $results, array $notes): array
    {
        $items = [];
        foreach (self::ITEMS as $item) {
            $result = is_scalar($results[$item] ?? null) ? (string) $results[$item] : '';
            if (!in_array($result, self::RESULTS, true)) {
                throw Validator::fail('results', 'fleet_check_incomplete');
            }
            $note = trim((string) (is_scalar($notes[$item] ?? null) ? $notes[$item] : ''));
            if (in_array($result, ['reserve', 'nok'], true) && $note === '') {
                throw Validator::fail('notes', 'fleet_check_note', ['item' => t('fleet_item.' . $item)]);
            }
            $items[$item] = ['result' => $result, 'note' => $note !== '' ? mb_substr($note, 0, 255) : null];
        }

        return $items;
    }

    /** Conducteur du personnel du site d'origine (nom et téléphone repris), ou nom saisi librement. */
    private function resolveDriver(array $data, int $siteId): array
    {
        $data += ['driver_id' => null, 'driver_name' => null, 'driver_phone' => null];
        if ($data['driver_id'] !== null) {
            $person = $this->db->fetchOne(
                "SELECT CONCAT(first_name, ' ', last_name) AS name, phone FROM personnel WHERE id = :id AND site_id = :s AND is_active = 1",
                ['id' => $data['driver_id'], 's' => $siteId]
            ) ?? throw Validator::fail('driver_id', 'exists');
            $data['driver_name'] = $person['name'];
            $data['driver_phone'] ??= $person['phone'];
        }

        return $data;
    }

    /** Compteurs relevés au départ / à l'arrivée : l'engin ne recule jamais, le relevé est historisé. */
    private function recordMeters(int $equipmentId, ?int $hours, ?int $km, ?int $fuel, string $note, int $userId): void
    {
        $this->db->execute(
            'UPDATE equipment SET hour_meter = CASE WHEN :h1 IS NULL THEN hour_meter ELSE GREATEST(COALESCE(hour_meter, 0), :h2) END,
                    odometer_km = CASE WHEN :k1 IS NULL THEN odometer_km ELSE GREATEST(COALESCE(odometer_km, 0), :k2) END,
                    fuel_level = COALESCE(:f, fuel_level), meter_updated_at = NOW()
              WHERE id = :id',
            ['h1' => $hours, 'h2' => $hours, 'k1' => $km, 'k2' => $km, 'f' => $fuel, 'id' => $equipmentId]
        );
        $this->db->execute(
            'INSERT INTO meter_readings (equipment_id, hour_meter, odometer_km, fuel_level, note, recorded_by) VALUES (:e, :h, :k, :f, :n, :u)',
            ['e' => $equipmentId, 'h' => $hours, 'k' => $km, 'f' => $fuel, 'n' => mb_substr($note, 0, 255), 'u' => $userId]
        );
    }
}
