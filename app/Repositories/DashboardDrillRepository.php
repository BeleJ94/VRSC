<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\DataTableRequest;
use App\Security\SiteScope;

/**
 * Listes de détail des tableaux de bord (fenêtres ouvertes depuis un indicateur, une barre, une alerte) :
 * décaissements, factures, engagements, lignes de coût, valeur du stock. Toujours dans le périmètre.
 * Chaque ligne porte « link », le chemin de sa page.
 */
final class DashboardDrillRepository extends Repository
{
    /** Colonnes triables, toutes listes confondues (chaque liste n'expose que les siennes). */
    public const SORTABLE = [
        'reference' => 'x.reference', 'date' => 'x.date', 'site' => 'x.site', 'party' => 'x.party', 'category' => 'x.category',
        'amount' => 'x.amount', 'status' => 'x.status', 'kind' => 'x.kind', 'due_date' => 'x.due_date', 'paid' => 'x.paid', 'unpaid' => 'x.unpaid',
        'equipment' => 'x.equipment', 'label' => 'x.label', 'quantity' => 'x.quantity', 'avg_cost' => 'x.avg_cost', 'value' => 'x.value',
    ];

    public function __construct(Database $db, SiteScope $scope, private readonly EquipmentCostRepository $costs)
    {
        parent::__construct($db, $scope);
    }

    /** Filtres communs : site, période (bornes incluses), sur la colonne de date de la liste. */
    private static function common(array $filters, array &$where, array &$params): void
    {
        if (!empty($filters['site_id'])) {
            $where[] = 'x.site_id = :f_site';
            $params['f_site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'x.date >= :f_from';
            $params['f_from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'x.date <= :f_to';
            $params['f_to'] = $filters['to'] . ' 23:59:59';
        }
    }

    /** Décaissements ; date = paiement (payés), approbation (approuvés) ou demande. */
    public function payments(DataTableRequest $dt, array $filters): array
    {
        [$scope, $params] = $this->scope->clause('p.site_id');
        $dateCol = ['paid' => 'p.paid_at', 'approved' => 'p.approved_at'][$filters['date_field'] ?? ''] ?? 'COALESCE(p.paid_at, p.approved_at, p.requested_at)';
        $base = $this->db->pdo()->quote(url('/finance/payments/'));
        $source = "(SELECT p.id, p.site_id, p.reference, $dateCol AS date, s.name AS site, COALESCE(sp.name, p.beneficiary, '—') AS party,
                           p.category, p.kind, p.amount, p.status, CONCAT($base, p.id) AS link
                      FROM payment_requests p JOIN sites s ON s.id = p.site_id LEFT JOIN suppliers sp ON sp.id = p.supplier_id
                     WHERE $scope) x";
        $where = [];
        $fp = [];
        self::common($filters, $where, $fp);
        foreach (['status', 'kind', 'category'] as $key) {
            if (!empty($filters[$key])) {
                $values = explode(',', (string) $filters[$key]);
                $marks = [];
                foreach ($values as $i => $v) {
                    $marks[] = ":f_{$key}_$i";
                    $fp["f_{$key}_$i"] = $v;
                }
                $where[] = "x.$key IN (" . implode(', ', $marks) . ')';
            }
        }

        return $this->paginate($dt, 'x.*', $source, [], $params, $where, $fp, ['x.reference', 'x.party', 'x.site']);
    }

    /** Factures fournisseurs, avec montant payé et reste à payer. */
    public function invoices(DataTableRequest $dt, array $filters): array
    {
        [$scope, $params] = $this->scope->clause('i.site_id');
        $base = $this->db->pdo()->quote(url('/purchases/'));
        $source = "(SELECT i.id, i.site_id, i.invoice_number AS reference, i.invoice_date AS date, i.due_date, s.name AS site, sp.name AS party, i.amount,
                           COALESCE(pd.paid, 0) AS paid, i.amount - COALESCE(pd.paid, 0) AS unpaid, CONCAT($base, i.order_id) AS link
                      FROM supplier_invoices i JOIN sites s ON s.id = i.site_id JOIN suppliers sp ON sp.id = i.supplier_id
                      LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM payment_requests WHERE status = 'paid' AND invoice_id IS NOT NULL GROUP BY invoice_id) pd ON pd.invoice_id = i.id
                     WHERE i.status = 'recorded' AND $scope) x";
        $where = [];
        $fp = [];
        self::common($filters, $where, $fp);
        if (!empty($filters['unpaid'])) {
            $where[] = 'x.unpaid > 0.005';
        }
        if (!empty($filters['overdue'])) {
            $where[] = 'x.unpaid > 0.005 AND x.due_date < CURDATE()';
        }

        return $this->paginate($dt, 'x.*', $source, [], $params, $where, $fp, ['x.reference', 'x.party', 'x.site']);
    }

    /** Engagements : bons de commande émis et dépenses directes / avances approuvées. */
    public function commitments(DataTableRequest $dt, array $filters): array
    {
        [$scopeO, $paramsO] = $this->scope->clause('o.site_id', 'so');
        [$scopeP, $paramsP] = $this->scope->clause('p.site_id', 'sp');
        $pdo = $this->db->pdo();
        $orderUrl = $pdo->quote(url('/purchases/'));
        $payUrl = $pdo->quote(url('/finance/payments/'));
        $source = "(SELECT o.id, o.site_id, o.reference, o.ordered_at AS date, s.name AS site, sp.name AS party, r.category, 'order' AS kind,
                           o.total_amount AS amount, o.status, CONCAT($orderUrl, o.id) AS link
                      FROM purchase_orders o JOIN purchase_requests r ON r.id = o.request_id JOIN sites s ON s.id = o.site_id JOIN suppliers sp ON sp.id = o.supplier_id
                     WHERE o.status <> 'cancelled' AND $scopeO
                    UNION ALL
                    SELECT p.id, p.site_id, p.reference, p.approved_at, s.name, COALESCE(p.beneficiary, '—'), p.category, p.kind, p.amount, p.status, CONCAT($payUrl, p.id)
                      FROM payment_requests p JOIN sites s ON s.id = p.site_id
                     WHERE p.kind <> 'invoice' AND p.status IN ('approved', 'paid') AND $scopeP) x";
        $where = [];
        $fp = [];
        self::common($filters, $where, $fp);
        if (!empty($filters['category'])) {
            $where[] = 'x.category = :f_cat';
            $fp['f_cat'] = (string) $filters['category'];
        }

        return $this->paginate($dt, 'x.*', $source, [], $paramsO + $paramsP, $where, $fp, ['x.reference', 'x.party', 'x.site']);
    }

    /** Commandes fournisseurs ; échéance = date de livraison attendue. */
    public function orders(DataTableRequest $dt, array $filters): array
    {
        [$scope, $params] = $this->scope->clause('o.site_id');
        $base = $this->db->pdo()->quote(url('/purchases/'));
        $source = "(SELECT o.id, o.site_id, o.reference, o.ordered_at AS date, o.expected_on AS due_date, sp.id AS supplier_id, sp.name AS party, s.name AS site,
                           r.reference AS label, o.status, o.total_amount AS amount, CONCAT($base, o.id) AS link
                      FROM purchase_orders o JOIN suppliers sp ON sp.id = o.supplier_id JOIN sites s ON s.id = o.site_id JOIN purchase_requests r ON r.id = o.request_id
                     WHERE $scope) x";
        $where = [];
        $fp = [];
        self::common($filters, $where, $fp);
        if (!empty($filters['status'])) {
            $marks = [];
            foreach (explode(',', (string) $filters['status']) as $i => $v) {
                $marks[] = ":f_st_$i";
                $fp["f_st_$i"] = $v;
            }
            $where[] = 'x.status IN (' . implode(', ', $marks) . ')';
        }
        if (!empty($filters['late'])) {
            $where[] = "x.status IN ('issued', 'partially_received') AND x.due_date < CURDATE()";
        }
        if (!empty($filters['supplier_id'])) {
            $where[] = 'x.supplier_id = :f_sup';
            $fp['f_sup'] = (int) $filters['supplier_id'];
        }

        return $this->paginate($dt, 'x.*', $source, [], $params, $where, $fp, ['x.reference', 'x.party', 'x.label', 'x.site']);
    }

    /** Lignes de coût imputées aux engins (pièces sources). */
    public function costLines(DataTableRequest $dt, array $filters): array
    {
        [$scope, $params] = $this->scope->clause('c.site_id');
        $base = $this->db->pdo()->quote(url('/costs/'));
        $source = "(SELECT c.equipment_id AS id, c.site_id, c.cost_date AS date, CONCAT(e.code, ' – ', e.name) AS equipment, s.name AS site, c.kind AS category,
                           c.label, c.quantity, c.unit, c.amount, c.source_type AS kind, c.document_ref AS reference, CONCAT($base, c.equipment_id) AS link
                      FROM " . $this->costs->linesSource() . " c JOIN equipment e ON e.id = c.equipment_id JOIN sites s ON s.id = c.site_id
                     WHERE $scope) x";
        $where = [];
        $fp = [];
        self::common($filters, $where, $fp);
        if (!empty($filters['equipment_id'])) {
            $where[] = 'x.id = :f_eq';
            $fp['f_eq'] = (int) $filters['equipment_id'];
        }
        if (!empty($filters['missing'])) {
            $where[] = 'x.amount IS NULL';
        }

        return $this->paginate($dt, 'x.*', $source, [], $params, $where, $fp, ['x.equipment', 'x.label', 'x.reference']);
    }

    /** Valeur du stock par article et magasin, au coût moyen. */
    public function stockValues(DataTableRequest $dt, array $filters): array
    {
        [$scope, $params] = $this->scope->clause('ps.site_id');
        $base = $this->db->pdo()->quote(url('/parts/'));
        $source = "(SELECT p.id, ps.site_id, p.reference, s.name AS site, p.name AS label, p.part_type AS category, ps.quantity, p.unit, ps.avg_cost,
                           ROUND(ps.quantity * COALESCE(ps.avg_cost, 0), 2) AS value, CONCAT($base, p.id) AS link
                      FROM part_stocks ps JOIN parts p ON p.id = ps.part_id JOIN sites s ON s.id = ps.site_id
                     WHERE ps.quantity > 0 AND $scope) x";
        $where = [];
        $fp = [];
        if (!empty($filters['site_id'])) {
            $where[] = 'x.site_id = :f_site';
            $fp['f_site'] = (int) $filters['site_id'];
        }

        return $this->paginate($dt, 'x.*', $source, [], $params, $where, $fp, ['x.reference', 'x.label', 'x.site']);
    }
}
