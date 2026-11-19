<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Transferts d'engins entre sites. Un transfert est visible dès que son site d'origine ou de destination
 * est dans le périmètre : l'engin lui-même n'entre dans le périmètre de la destination qu'après réception.
 */
final class FleetTransferRepository extends Repository
{
    /** Étapes en attente, dans l'ordre du circuit. */
    public const STAGES = ['opinion', 'control', 'authorization', 'departure', 'reception'];

    /** @return array{0: string, 1: array<string, int>} */
    private function visibility(): array
    {
        [$fromSql, $fromParams] = $this->scope->clause('t.from_site_id', 'ff');
        [$toSql, $toParams] = $this->scope->clause('t.to_site_id', 'ft');

        return ["($fromSql OR $toSql)", $fromParams + $toParams];
    }

    private const SELECT = "t.*, e.code AS equipment_code, e.name AS equipment_name, e.plate_number, e.chassis_number, e.serial_number,
            e.hour_meter AS equipment_hours, e.odometer_km AS equipment_km, e.fuel_level AS equipment_fuel,
            COALESCE(e.meter_basis, cat.meter_basis, 'hours') AS meter_basis, cat.name AS category,
            fs.name AS from_site, ts.name AS to_site, c.name AS carrier,
            CONCAT(u.first_name, ' ', u.last_name) AS requester,
            CONCAT(du.first_name, ' ', du.last_name) AS dispatcher,
            CONCAT(ru.first_name, ' ', ru.last_name) AS receiver,
            ar.id AS approval_id, st.code AS approval_step,
            CASE WHEN t.status = 'requested' THEN COALESCE(st.code, 'authorization')
                 WHEN t.status = 'authorized' THEN 'departure'
                 WHEN t.status = 'departed' THEN 'reception' END AS stage,
            (SELECT k.conclusion FROM fleet_transfer_checks k WHERE k.transfer_id = t.id AND k.kind = 'pre_departure' ORDER BY k.id DESC LIMIT 1) AS last_conclusion,
            CASE WHEN t.status IN ('requested', 'authorized') AND t.planned_departure IS NOT NULL AND t.planned_departure < NOW() THEN 1
                 WHEN t.status = 'departed' AND t.planned_arrival IS NOT NULL AND t.planned_arrival < NOW() THEN 1 ELSE 0 END AS is_late,
            CASE WHEN t.departed_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, t.departed_at, COALESCE(t.received_at, NOW())) END AS transit_hours";

    private const FROM = "fleet_transfers t
            JOIN equipment e ON e.id = t.equipment_id
            LEFT JOIN equipment_categories cat ON cat.id = e.category_id
            JOIN sites fs ON fs.id = t.from_site_id
            JOIN sites ts ON ts.id = t.to_site_id
            LEFT JOIN carriers c ON c.id = t.carrier_id
            LEFT JOIN users u ON u.id = t.requested_by
            LEFT JOIN users du ON du.id = t.departed_by
            LEFT JOIN users ru ON ru.id = t.received_by
            LEFT JOIN approval_requests ar ON ar.entity_type = 'fleet_transfer' AND ar.entity_id = t.id AND ar.status = 'pending'
            LEFT JOIN approval_flow_steps st ON st.id = ar.current_step_id";

    /** @param array{stage?: ?string, status?: ?string, site_id?: ?int, equipment_id?: ?int} $filters */
    public function all(array $filters = []): array
    {
        [$where, $params] = $this->visibility();
        if (!empty($filters['status'])) {
            $where .= ' AND t.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['site_id'])) {
            $where .= ' AND (t.from_site_id = :s1 OR t.to_site_id = :s2)';
            $params += ['s1' => (int) $filters['site_id'], 's2' => (int) $filters['site_id']];
        }
        if (!empty($filters['equipment_id'])) {
            $where .= ' AND t.equipment_id = :eq';
            $params['eq'] = (int) $filters['equipment_id'];
        }
        $rows = $this->db->fetchAll(
            'SELECT ' . self::SELECT . ' FROM ' . self::FROM . " WHERE $where
              ORDER BY FIELD(t.status, 'requested', 'authorized', 'departed', 'received', 'rejected', 'cancelled'), t.id DESC LIMIT 300",
            $params
        );
        if (!empty($filters['stage'])) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['stage'] === $filters['stage']));
        }

        return $rows;
    }

    public function find(int $id, bool $forUpdate = false): ?array
    {
        [$where, $params] = $this->visibility();
        if ($forUpdate) {
            $locked = $this->db->fetchOne("SELECT t.id FROM fleet_transfers t WHERE t.id = :id AND $where FOR UPDATE", ['id' => $id] + $params);
            if ($locked === null) {
                return null;
            }
        }

        return $this->db->fetchOne('SELECT ' . self::SELECT . ' FROM ' . self::FROM . " WHERE t.id = :id AND $where", ['id' => $id] + $params);
    }

    /** Transfert en cours pour un engin (demandé, autorisé ou parti), tous sites confondus. */
    public function activeFor(int $equipmentId): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, reference, status FROM fleet_transfers WHERE equipment_id = :e AND status IN ('requested', 'authorized', 'departed') LIMIT 1",
            ['e' => $equipmentId]
        );
    }

    public function create(array $data): int
    {
        $columns = array_keys($data);

        return $this->db->insert(
            'INSERT INTO fleet_transfers (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_map(static fn (string $c): string => ":$c", $columns)) . ')',
            $data
        );
    }

    public function update(int $id, array $fields): void
    {
        $allowed = ['status', 'authorized_at', 'departed_at', 'departed_by', 'departure_hours', 'departure_km', 'departure_fuel', 'handover_to',
            'driver_id', 'driver_name', 'driver_phone', 'carrier_id', 'tractor_plate', 'trailer_plate', 'convoy_leader', 'observations',
            'received_at', 'received_by', 'arrival_hours', 'arrival_km', 'arrival_fuel', 'has_discrepancy', 'arrival_note', 'cancel_reason'];
        $set = [];
        $params = ['id' => $id];
        foreach ($fields as $name => $value) {
            if (in_array($name, $allowed, true)) {
                $set[] = "$name = :$name";
                $params[$name] = $value;
            }
        }
        if ($set !== []) {
            $this->db->execute('UPDATE fleet_transfers SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
        }
    }

    // --- Contrôles ---------------------------------------------------------------

    /** @param array<string, array{result: string, note: ?string}> $items */
    public function addCheck(array $data, array $items): int
    {
        $id = $this->db->insert(
            'INSERT INTO fleet_transfer_checks (transfer_id, kind, conclusion, remarks, hour_meter, odometer_km, checked_by, checker_name)
             VALUES (:transfer_id, :kind, :conclusion, :remarks, :hour_meter, :odometer_km, :checked_by, :checker_name)',
            $data
        );
        foreach ($items as $item => $entry) {
            $this->db->execute(
                'INSERT INTO fleet_transfer_check_items (check_id, item, result, note) VALUES (:c, :i, :r, :n)',
                ['c' => $id, 'i' => $item, 'r' => $entry['result'], 'n' => $entry['note']]
            );
        }

        return $id;
    }

    /** Tous les contrôles d'un transfert, du plus ancien au plus récent, avec leurs points. */
    public function checks(int $transferId): array
    {
        $checks = $this->db->fetchAll('SELECT * FROM fleet_transfer_checks WHERE transfer_id = :t ORDER BY id', ['t' => $transferId]);
        foreach ($checks as &$check) {
            $check['items'] = [];
            foreach ($this->db->fetchAll('SELECT item, result, note FROM fleet_transfer_check_items WHERE check_id = :c', ['c' => $check['id']]) as $item) {
                $check['items'][$item['item']] = $item;
            }
        }
        unset($check);

        return $checks;
    }

    public function latestCheck(int $transferId, string $kind): ?array
    {
        $checks = array_values(array_filter($this->checks($transferId), static fn (array $c): bool => $c['kind'] === $kind));

        return $checks === [] ? null : $checks[count($checks) - 1];
    }

    // --- Affectation par site ------------------------------------------------------

    /** Historique des sites d'un engin (ventilation temporelle). */
    public function siteHistory(int $equipmentId): array
    {
        return $this->db->fetchAll(
            'SELECT h.*, s.name AS site, t.reference AS transfer_reference
               FROM equipment_site_history h JOIN sites s ON s.id = h.site_id LEFT JOIN fleet_transfers t ON t.id = h.transfer_id
              WHERE h.equipment_id = :e ORDER BY h.started_at DESC, h.id DESC',
            ['e' => $equipmentId]
        );
    }

    /** Documents de bord en vigueur de l'engin, pour mention sur l'OTV. */
    public function onBoardDocuments(int $equipmentId): array
    {
        return $this->db->fetchAll(
            'SELECT dt.name AS type, d.reference, d.expires_at
               FROM equipment_documents d JOIN document_types dt ON dt.id = d.document_type_id
              WHERE d.equipment_id = :e AND d.id = (SELECT d2.id FROM equipment_documents d2
                     WHERE d2.equipment_id = d.equipment_id AND d2.document_type_id = d.document_type_id ORDER BY d2.expires_at DESC, d2.id DESC LIMIT 1)
              ORDER BY dt.sort_order',
            ['e' => $equipmentId]
        );
    }
}
