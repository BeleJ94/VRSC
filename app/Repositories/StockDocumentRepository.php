<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Documents de stock : transferts intermagasins et inventaires physiques.
 * Un transfert est visible dès qu'un de ses deux magasins est dans le périmètre.
 */
final class StockDocumentRepository extends Repository
{
    // --- Transferts -------------------------------------------------------------

    /** @return array{0: string, 1: array<string, int>} */
    private function transferVisibility(): array
    {
        [$fromSql, $fromParams] = $this->scope->clause('t.from_site_id', 'tf');
        [$toSql, $toParams] = $this->scope->clause('t.to_site_id', 'tt');

        return ["($fromSql OR $toSql)", $fromParams + $toParams];
    }

    public function transfers(?string $status = null, ?int $siteId = null): array
    {
        [$where, $params] = $this->transferVisibility();
        if ($status !== null) {
            $where .= ' AND t.status = :status';
            $params['status'] = $status;
        }
        if ($siteId !== null) {
            $where .= ' AND (t.from_site_id = :s1 OR t.to_site_id = :s2)';
            $params += ['s1' => $siteId, 's2' => $siteId];
        }

        return $this->db->fetchAll(
            "SELECT t.*, fs.name AS from_site, ts.name AS to_site, CONCAT(u.first_name, ' ', u.last_name) AS requester,
                    (SELECT COUNT(*) FROM stock_transfer_lines l WHERE l.transfer_id = t.id) AS line_count,
                    DATEDIFF(COALESCE(t.received_at, NOW()), t.requested_at) AS age_days
               FROM stock_transfers t
               JOIN sites fs ON fs.id = t.from_site_id
               JOIN sites ts ON ts.id = t.to_site_id
               LEFT JOIN users u ON u.id = t.requested_by
              WHERE $where
              ORDER BY FIELD(t.status, 'requested', 'authorized', 'shipped', 'received', 'rejected', 'cancelled'), t.id DESC
              LIMIT 300",
            $params
        );
    }

    public function findTransfer(int $id, bool $forUpdate = false): ?array
    {
        [$where, $params] = $this->transferVisibility();

        return $this->db->fetchOne(
            "SELECT t.*, fs.name AS from_site, ts.name AS to_site, c.name AS carrier,
                    CONCAT(u.first_name, ' ', u.last_name) AS requester,
                    CONCAT(su.first_name, ' ', su.last_name) AS shipper,
                    CONCAT(ru.first_name, ' ', ru.last_name) AS receiver
               FROM stock_transfers t
               JOIN sites fs ON fs.id = t.from_site_id
               JOIN sites ts ON ts.id = t.to_site_id
               LEFT JOIN carriers c ON c.id = t.carrier_id
               LEFT JOIN users u ON u.id = t.requested_by
               LEFT JOIN users su ON su.id = t.shipped_by
               LEFT JOIN users ru ON ru.id = t.received_by
              WHERE t.id = :id AND $where" . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $id] + $params
        );
    }

    public function transferLines(int $transferId): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, p.reference, p.name, p.unit,
                    COALESCE(fs.quantity, 0) AS source_quantity, COALESCE(fs.reserved, 0) AS source_reserved
               FROM stock_transfer_lines l
               JOIN stock_transfers t ON t.id = l.transfer_id
               JOIN parts p ON p.id = l.part_id
               LEFT JOIN part_stocks fs ON fs.part_id = l.part_id AND fs.site_id = t.from_site_id
              WHERE l.transfer_id = :t ORDER BY p.reference',
            ['t' => $transferId]
        );
    }

    public function createTransfer(array $data, array $lines): int
    {
        $id = $this->db->insert(
            'INSERT INTO stock_transfers (reference, from_site_id, to_site_id, reason, carrier_id, requested_by)
             VALUES (:reference, :from_site_id, :to_site_id, :reason, :carrier_id, :requested_by)',
            $data
        );
        foreach ($lines as $line) {
            $this->db->execute(
                'INSERT INTO stock_transfer_lines (transfer_id, part_id, qty_requested) VALUES (:t, :p, :q)',
                ['t' => $id, 'p' => $line['part_id'], 'q' => $line['quantity']]
            );
        }

        return $id;
    }

    public function updateTransfer(int $id, array $fields): void
    {
        $allowed = ['status', 'authorized_at', 'shipped_by', 'shipped_at', 'received_by', 'received_at', 'has_discrepancy', 'discrepancy_note', 'carrier_id'];
        $set = [];
        $params = ['id' => $id];
        foreach ($fields as $name => $value) {
            if (in_array($name, $allowed, true)) {
                $set[] = "$name = :$name";
                $params[$name] = $value;
            }
        }
        if ($set !== []) {
            $this->db->execute('UPDATE stock_transfers SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
        }
    }

    public function updateTransferLine(int $lineId, array $fields): void
    {
        $set = [];
        $params = ['id' => $lineId];
        foreach (['qty_shipped', 'qty_received', 'unit_cost'] as $name) {
            if (array_key_exists($name, $fields)) {
                $set[] = "$name = :$name";
                $params[$name] = $fields[$name];
            }
        }
        if ($set !== []) {
            $this->db->execute('UPDATE stock_transfer_lines SET ' . implode(', ', $set) . ' WHERE id = :id', $params);
        }
    }

    /** Stock disponible d'un article dans les magasins du périmètre (recherche avant transfert ou achat). */
    public function availability(int $partId): array
    {
        [$scopeSql, $params] = $this->scope->clause('ps.site_id');

        return $this->db->fetchAll(
            "SELECT s.id AS site_id, s.name AS site, ps.quantity, ps.reserved, ps.quantity - ps.reserved AS available
               FROM part_stocks ps JOIN sites s ON s.id = ps.site_id AND s.is_active = 1
              WHERE ps.part_id = :part AND ps.quantity - ps.reserved > 0 AND $scopeSql
              ORDER BY available DESC",
            ['part' => $partId] + $params
        );
    }

    // --- Inventaires ------------------------------------------------------------

    public function inventories(?int $siteId = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('i.site_id');
        $where = $scopeSql;
        if ($siteId !== null) {
            $where .= ' AND i.site_id = :site';
            $params['site'] = $siteId;
        }

        return $this->db->fetchAll(
            "SELECT i.*, s.name AS site, CONCAT(u.first_name, ' ', u.last_name) AS creator,
                    (SELECT COUNT(*) FROM stock_inventory_lines l WHERE l.inventory_id = i.id) AS line_count,
                    (SELECT COUNT(*) FROM stock_inventory_lines l WHERE l.inventory_id = i.id AND l.qty_counted IS NOT NULL
                        AND l.qty_counted <> l.qty_theoretical) AS gap_count
               FROM stock_inventories i JOIN sites s ON s.id = i.site_id LEFT JOIN users u ON u.id = i.created_by
              WHERE $where
              ORDER BY FIELD(i.status, 'draft', 'submitted', 'approved', 'rejected', 'cancelled'), i.id DESC LIMIT 300",
            $params
        );
    }

    public function findInventory(int $id, bool $forUpdate = false): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('i.site_id');

        return $this->db->fetchOne(
            "SELECT i.*, s.name AS site, CONCAT(u.first_name, ' ', u.last_name) AS creator
               FROM stock_inventories i JOIN sites s ON s.id = i.site_id LEFT JOIN users u ON u.id = i.created_by
              WHERE i.id = :id AND $scopeSql" . ($forUpdate ? ' FOR UPDATE' : ''),
            ['id' => $id] + $params
        );
    }

    public function inventoryLines(int $inventoryId): array
    {
        return $this->db->fetchAll(
            'SELECT l.*, p.reference, p.name, p.unit, ps.location,
                    CASE WHEN l.qty_counted IS NOT NULL THEN l.qty_counted - l.qty_theoretical END AS gap,
                    CASE WHEN l.qty_counted IS NOT NULL AND l.unit_cost IS NOT NULL THEN ROUND((l.qty_counted - l.qty_theoretical) * l.unit_cost, 2) END AS gap_value
               FROM stock_inventory_lines l
               JOIN stock_inventories i ON i.id = l.inventory_id
               JOIN parts p ON p.id = l.part_id
               LEFT JOIN part_stocks ps ON ps.part_id = l.part_id AND ps.site_id = i.site_id
              WHERE l.inventory_id = :i ORDER BY ps.location IS NULL, ps.location, p.reference',
            ['i' => $inventoryId]
        );
    }

    /** Ouvre un inventaire en photographiant le stock théorique de tous les articles du magasin. */
    public function createInventory(array $data): int
    {
        $this->scope->assertAllows((int) $data['site_id']);
        $id = $this->db->insert(
            'INSERT INTO stock_inventories (reference, site_id, counted_on, note, created_by) VALUES (:reference, :site_id, :counted_on, :note, :created_by)',
            $data
        );
        $this->db->execute(
            'INSERT INTO stock_inventory_lines (inventory_id, part_id, qty_theoretical, unit_cost)
             SELECT :i, ps.part_id, ps.quantity, COALESCE(ps.avg_cost, NULLIF(p.unit_cost, 0))
               FROM part_stocks ps JOIN parts p ON p.id = ps.part_id AND p.is_active = 1
              WHERE ps.site_id = :s',
            ['i' => $id, 's' => $data['site_id']]
        );

        return $id;
    }

    public function setCount(int $inventoryId, int $partId, ?float $counted, ?string $justification): void
    {
        $this->db->execute(
            'UPDATE stock_inventory_lines SET qty_counted = :c, justification = :j WHERE inventory_id = :i AND part_id = :p',
            ['c' => $counted, 'j' => $justification, 'i' => $inventoryId, 'p' => $partId]
        );
    }

    public function updateInventory(int $id, string $status, bool $submitted = false): void
    {
        $this->db->execute(
            "UPDATE stock_inventories SET status = :s,
                    submitted_at = IF(:sub = 1, NOW(), submitted_at),
                    closed_at = IF(:s2 IN ('approved','rejected','cancelled'), NOW(), closed_at)
              WHERE id = :id",
            ['s' => $status, 's2' => $status, 'sub' => $submitted ? 1 : 0, 'id' => $id]
        );
    }

    public function hasOpenInventory(int $siteId): bool
    {
        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM stock_inventories WHERE site_id = :s AND status IN ('draft','submitted') LIMIT 1",
            ['s' => $siteId]
        );
    }
}
