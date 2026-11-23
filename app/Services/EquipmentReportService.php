<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\EquipmentCostRepository;
use App\Repositories\EquipmentDocumentRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\PreventivePlanRepository;
use App\Repositories\WorkOrderRepository;
use App\Security\Auth;

/**
 * Fiche exécutive d'un engin (PDF et Excel) : identité, indicateurs clés, coûts sur 12 mois, ordres de travail,
 * préventif, documents, historique d'état et relevés. Les textes arrivent formatés (PDF) et les nombres bruts (Excel).
 * Les coûts ne figurent que pour les profils autorisés à les voir (costs.view).
 */
final class EquipmentReportService
{
    private const MONTHS = 12;

    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
        private readonly EquipmentRepository $equipment,
        private readonly WorkOrderRepository $workOrders,
        private readonly PreventivePlanRepository $plans,
        private readonly EquipmentDocumentRepository $documents,
        private readonly EquipmentCostRepository $costs,
    ) {
    }

    public function build(int $id): array
    {
        $e = $this->equipment->find($id) ?? throw HttpException::notFound();
        $user = $this->auth->user();
        $canCosts = $user !== null && $user->can('costs.view');
        $period = date('Y-m');
        $from = date('Y-m-01', (int) strtotime($period . '-01 -' . (self::MONTHS - 1) . ' month'));
        $to = date('Y-m-d');
        $basisKm = ($e['meter_basis'] ?? null) === 'km';

        // Ordres de travail (12 mois) et coût des pièces de chacun.
        $orders = $this->db->fetchAll(
            "SELECT w.id, w.reference, w.title, w.type, w.status, w.priority, w.reported_at, w.completed_at, w.labor_minutes,
                    (SELECT COALESCE(SUM(wp.quantity * wp.unit_cost), 0) FROM work_order_parts wp WHERE wp.work_order_id = w.id) AS parts_cost
               FROM work_orders w WHERE w.equipment_id = :e AND w.reported_at >= :f ORDER BY w.reported_at DESC, w.id DESC",
            ['e' => $id, 'f' => $from]
        );
        $openWo = count(array_filter($orders, static fn (array $w) => in_array($w['status'], ['open', 'in_progress', 'waiting_part'], true)))
            + (int) $this->db->fetchValue("SELECT COUNT(*) FROM work_orders WHERE equipment_id = :e AND reported_at < :f AND status IN ('open','in_progress','waiting_part')", ['e' => $id, 'f' => $from]);
        $breakdowns = count(array_filter($orders, static fn (array $w) => $w['type'] === 'corrective'));

        // Utilisation sur la période : écart entre le premier et le dernier relevé.
        $usage = $this->db->fetchOne(
            'SELECT MIN(hour_meter) AS h0, MAX(hour_meter) AS h1, MIN(odometer_km) AS k0, MAX(odometer_km) AS k1
               FROM meter_readings WHERE equipment_id = :e AND recorded_at >= :f',
            ['e' => $id, 'f' => $from]
        ) ?? [];
        $used = $basisKm ? ((int) ($usage['k1'] ?? 0) - (int) ($usage['k0'] ?? 0)) : ((int) ($usage['h1'] ?? 0) - (int) ($usage['h0'] ?? 0));
        $unit = $basisKm ? 'km' : 'h';

        $months = $canCosts ? $this->costs->history($id, $period, self::MONTHS) : [];
        $lines = $canCosts ? $this->costs->detail($id, $from, $to) : [];
        $byKind = [];
        foreach ($lines as $l) {
            $byKind[$l['kind']] = ($byKind[$l['kind']] ?? 0) + (float) ($l['amount'] ?? 0);
        }
        arsort($byKind);
        $total = array_sum(array_column($months, 'total'));
        $litres = array_sum(array_column($months, 'litres'));

        $kpis = [
            ['label' => t('fields.status'), 'value' => t('status.' . $e['status']), 'hint' => $e['site'] ?? ''],
            ['label' => t($basisKm ? 'fields.odometer_km' : 'fields.hour_meter'), 'value' => $basisKm
                ? ($e['odometer_km'] !== null ? format_number($e['odometer_km']) . ' km' : '—')
                : ($e['hour_meter'] !== null ? format_number($e['hour_meter']) . ' h' : '—'),
                'hint' => t('report_eq.usage', ['value' => format_number(max(0, $used)), 'unit' => $unit])],
            ['label' => t('report_eq.kpi_work_orders'), 'value' => (string) count($orders), 'hint' => t('report_eq.kpi_work_orders_hint', ['open' => $openWo, 'breakdowns' => $breakdowns])],
        ];
        if ($canCosts) {
            $kpis[] = ['label' => t('report_eq.kpi_cost'), 'value' => format_money($total), 'hint' => $used > 0 ? t('report_eq.per_unit', ['value' => format_money($total / $used), 'unit' => $unit]) : ''];
            $kpis[] = ['label' => t('cost_kind.fuel'), 'value' => format_number($litres, 0) . ' L', 'hint' => $used > 0 && $litres > 0 ? format_number($basisKm ? $litres / $used * 100 : $litres / $used, 2) . ($basisKm ? ' L/100 km' : ' L/h') : ''];
        }

        $identity = array_values(array_filter([
            [t('fields.code'), $e['code']],
            [t('fields.name'), $e['name']],
            [t('fields.brand_id') . ' / ' . t('fields.model_id'), trim(($e['brand'] ?? '') . ' ' . ($e['model'] ?? '')) ?: '—'],
            [t('fields.category_id'), $e['category'] ?? '—'],
            [t('fields.site_id'), $e['site'] ?? '—'],
            [t('fields.project_id'), $e['project'] ? ($e['project_code'] . ' – ' . $e['project']) : '—'],
            [t('fields.serial_number'), $e['serial_number'] ?: '—'],
            [t('fields.chassis_number'), $e['chassis_number'] ?: '—'],
            [t('fields.plate_number'), $e['plate_number'] ?: '—'],
            [t('fields.year_made'), $e['year_made'] ? (string) $e['year_made'] : '—'],
            [t('fields.commissioned_at'), format_date($e['commissioned_at']) ?: '—'],
            [t('fields.ownership'), t('ownership.' . $e['ownership']) . ($e['ownership'] !== 'owned' && $e['contract_ref'] ? ' · ' . $e['contract_ref'] : '')],
            $e['ownership'] !== 'owned' ? [t('fields.contract_party'), $e['contract_party'] ?: '—'] : null,
            $e['ownership'] !== 'owned' ? [t('equipment.contract_period'), (format_date($e['contract_start']) ?: '…') . ' – ' . (format_date($e['contract_end']) ?: '…')] : null,
            [t('fields.assignment'), $e['assignment'] ?? '—'],
        ]));

        $documents = [];
        foreach ($this->documents->forEquipment($id) as $group) {
            $doc = $group['current'];
            $state = $doc === null ? ($group['exemption'] !== null ? 'na' : 'missing') : (string) $doc['state'];
            $documents[] = [
                doc_type_label($group['type']),
                $doc ? implode(' · ', array_filter([$doc['issuer'], $doc['reference']])) : ($group['exemption'] ?? ''),
                $doc && $doc['expires_at'] ? format_date($doc['expires_at']) : '—',
                t('report_eq_doc.' . $state),
                ['expired' => 'bad', 'missing' => 'bad', 'soon' => 'warn', 'renewing' => 'warn'][$state] ?? 'good',
            ];
        }

        $interval = static function (array $p): string {
            return implode(' / ', array_filter([
                $p['interval_days'] ? t('report_eq.every_days', ['n' => $p['interval_days']]) : null,
                $p['interval_hours'] ? t('report_eq.every_hours', ['n' => format_number($p['interval_hours'])]) : null,
                $p['interval_km'] ? t('report_eq.every_km', ['n' => format_number($p['interval_km'])]) : null,
            ]));
        };

        return [
            'title' => t('report_eq.title'),
            'code' => $e['code'],
            'name' => $e['name'],
            'subtitle' => trim(($e['brand'] ?? '') . ' ' . ($e['model'] ?? '')) . ' · ' . ($e['site'] ?? '') . ' · ' . t('report_eq.period', ['from' => format_date($from), 'to' => format_date($to)]),
            'generated' => t('dashboard.report_generated', ['date' => format_date(date('Y-m-d H:i:s'), true), 'name' => $user?->name() ?? '']),
            'confidential' => t('js.drill.confidential'),
            'filename' => 'fiche-engin-' . preg_replace('/[^A-Za-z0-9-]+/', '-', (string) $e['code']) . '-' . date('Ymd'),
            'currency' => t('report_eq.currency'),
            'labels' => [
                'identity' => t('equipment.details'), 'kpis' => t('report_eq.kpis'), 'costs' => t('report_eq.costs'), 'cost_kinds' => t('report_eq.cost_kinds'),
                'months' => t('report_eq.months'), 'work_orders' => t('report_eq.work_orders'), 'preventive' => t('nav.preventive'), 'documents' => t('documents.title'),
                'history' => t('equipment.status_history'), 'readings' => t('equipment.readings'), 'cost_lines' => t('report_eq.cost_lines'), 'summary' => t('report_eq.summary'),
                'none' => t('common.none'), 'total' => t('js.total'),
            ],
            'identity' => $identity,
            'kpis' => $kpis,
            'show_costs' => $canCosts,
            'cost_kinds' => [
                'headers' => [t('report_eq.col_kind'), t('report_eq.col_amount'), t('report_eq.col_share')],
                'rows' => array_map(static fn (string $k, float $v): array => [t('cost_kind.' . $k), $v, $total > 0 ? round($v / $total * 100, 1) : 0], array_keys($byKind), array_values($byKind)),
                'total' => $total,
            ],
            'months' => [
                'headers' => [t('report_eq.col_month'), t('report_eq.col_amount'), t('report_eq.col_litres')],
                'rows' => array_map(static fn (array $m): array => [$m['period'], (float) $m['total'], (float) $m['litres']], $months),
            ],
            'cost_lines' => [
                'headers' => [t('fields.date'), t('report_eq.col_kind'), t('report_eq.col_label'), t('fields.quantity'), t('report_eq.col_amount'), t('report_eq.col_document')],
                'rows' => array_map(static fn (array $l): array => [$l['cost_date'], t('cost_kind.' . $l['kind']), (string) ($l['label'] ?? ''), $l['quantity'] !== null ? (float) $l['quantity'] : null, $l['amount'] !== null ? (float) $l['amount'] : null, (string) ($l['document_ref'] ?? '')], $lines),
            ],
            'work_orders' => [
                'headers' => [t('fields.reference'), t('fields.title'), t('fields.type'), t('fields.status'), t('fields.reported_at'), t('fields.completed_at'), t('work_orders.parts_cost')],
                'rows' => array_map(static fn (array $w): array => [$w['reference'], $w['title'], t('wo_type.' . $w['type']), t('wo_status.' . $w['status']), substr((string) $w['reported_at'], 0, 10), $w['completed_at'] ? substr((string) $w['completed_at'], 0, 10) : '', (float) $w['parts_cost']], $orders),
            ],
            'preventive' => [
                'headers' => [t('fields.title'), t('report_eq.col_interval'), t('report_eq.col_next'), t('fields.is_active')],
                'rows' => array_map(static fn (array $p): array => [$p['title'], $interval($p), implode(' · ', array_filter([
                    $p['next_due_date'] ? format_date($p['next_due_date']) : null,
                    $p['next_due_hours'] !== null ? format_number($p['next_due_hours']) . ' h' : null,
                    $p['next_due_km'] !== null ? format_number($p['next_due_km']) . ' km' : null,
                ])) ?: '—', (int) $p['is_active'] ? t('js.yes') : t('js.no')], $this->plans->forEquipment($id)),
            ],
            'documents' => [
                'headers' => [t('report_eq.col_document_type'), t('report_eq.col_reference'), t('report_eq.col_expiry'), t('fields.status')],
                'rows' => $documents,
            ],
            'history' => [
                'headers' => [t('fields.date'), t('report_eq.col_from'), t('report_eq.col_to'), t('fields.reason'), t('report_eq.col_by')],
                'rows' => array_map(static fn (array $h): array => [substr((string) $h['changed_at'], 0, 16), $h['from_status'] ? t('status.' . $h['from_status']) : '—', t('status.' . $h['to_status']), (string) ($h['reason'] ?? ''), (string) ($h['changed_by'] ?? '')], $this->equipment->statusHistory($id, 200)),
            ],
            'readings' => [
                'headers' => [t('fields.date'), t('fields.hour_meter'), t('fields.odometer_km'), t('report_eq.col_fuel'), t('report_eq.col_by')],
                'rows' => array_map(static fn (array $r): array => [substr((string) $r['recorded_at'], 0, 16), $r['hour_meter'] !== null ? (int) $r['hour_meter'] : null, $r['odometer_km'] !== null ? (int) $r['odometer_km'] : null, $r['fuel_level'] !== null ? (int) $r['fuel_level'] : null, (string) ($r['recorded_by'] ?? '')], $this->equipment->readings($id, 500)),
            ],
        ];
    }
}
