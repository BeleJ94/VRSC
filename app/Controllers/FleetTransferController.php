<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\FleetTransferRepository;
use App\Repositories\ReferenceRepository;
use App\Services\ApprovalService;
use App\Services\AttachmentService;
use App\Services\FleetTransferService;

/** Transferts d'engins entre sites : demande, check-list, OTV, départ, procès-verbal de réception, suivi. */
final class FleetTransferController extends Controller
{
    public const STATUSES = ['requested', 'authorized', 'departed', 'received', 'rejected', 'cancelled'];

    public function __construct(
        ControllerContext $ctx,
        private readonly FleetTransferRepository $transfers,
        private readonly FleetTransferService $service,
        private readonly ApprovalService $approvals,
        private readonly AttachmentService $attachments,
        private readonly ReferenceRepository $references,
        private readonly Database $db,
    ) {
        parent::__construct($ctx);
    }

    public function index(Request $request): Response
    {
        $stage = $request->string('stage');
        $status = $request->string('status');
        $all = $this->transfers->all(['site_id' => $this->siteFilter($request)]);
        $counts = array_fill_keys(FleetTransferRepository::STAGES, 0);
        $late = 0;
        $discrepancies = 0;
        foreach ($all as $row) {
            if ($row['stage'] !== null) {
                $counts[$row['stage']]++;
            }
            $late += (int) $row['is_late'];
            $discrepancies += (int) $row['has_discrepancy'];
        }
        $rows = $all;
        if (in_array($stage, FleetTransferRepository::STAGES, true)) {
            $rows = array_values(array_filter($all, static fn (array $r): bool => $r['stage'] === $stage));
        } elseif (in_array($status, self::STATUSES, true)) {
            $rows = array_values(array_filter($all, static fn (array $r): bool => $r['status'] === $status));
        } elseif ($request->string('late') === '1') {
            $rows = array_values(array_filter($all, static fn (array $r): bool => (int) $r['is_late'] === 1));
        }

        return $this->render('fleet_transfers/index', [
            'title' => t('nav.fleet_transfers'),
            'transfers' => $rows,
            'counts' => $counts,
            'late' => $late,
            'discrepancies' => $discrepancies,
            'stage' => $stage,
            'status' => $status,
            'canRequest' => $this->user()->can('fleet.transfer'),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->user();
        $scope = $user->scopeFor('fleet.transfer');
        // Engins proposés : ceux des sites où l'on peut demander ; un site de destination peut aussi demander un engin d'ailleurs.
        $equipment = $this->db->fetchAll(
            "SELECT e.id, e.code, e.name, e.site_id, s.name AS site FROM equipment e JOIN sites s ON s.id = e.site_id
              WHERE e.is_archived = 0 AND e.id NOT IN (SELECT equipment_id FROM fleet_transfers WHERE status IN ('requested', 'authorized', 'departed'))
              ORDER BY s.name, e.code LIMIT 3000"
        );

        return $this->render('fleet_transfers/form', [
            'title' => t('fleet.new'),
            'equipment' => $equipment,
            'equipmentId' => $request->int('equipment_id'),
            'sites' => $this->db->fetchAll('SELECT id, name FROM sites WHERE is_active = 1 ORDER BY name'),
            'ownSites' => $scope,
            'carriers' => $this->references->options('carriers'),
            'drivers' => $this->db->fetchAll(
                "SELECT p.id, CONCAT(p.first_name, ' ', p.last_name) AS name, p.phone, s.name AS site FROM personnel p JOIN sites s ON s.id = p.site_id
                  WHERE p.is_active = 1 AND p.job IN ('driver', 'operator') ORDER BY s.name, p.last_name LIMIT 2000"
            ),
        ]);
    }

    public function store(Request $request): Response
    {
        $id = $this->service->request($request->body);

        return $this->redirect("/fleet-transfers/$id", t('flash.created'));
    }

    public function show(Request $request, array $params): Response
    {
        $transfer = $this->transfers->find($this->id($params)) ?? throw HttpException::notFound();
        $id = (int) $transfer['id'];
        $user = $this->user();
        $from = (int) $transfer['from_site_id'];
        $to = (int) $transfer['to_site_id'];
        $checks = $this->transfers->checks($id);
        foreach ($checks as &$check) {
            $check['photos'] = $this->attachments->list('fleet_transfer_check', (int) $check['id'], 'photo');
        }
        unset($check);
        $approval = $this->approvals->forEntity(FleetTransferService::ENTITY, $id, true);
        $lastCheck = $this->transfers->latestCheck($id, 'pre_departure');
        $awaitingControl = $transfer['status'] === 'requested' && $transfer['approval_step'] === 'control';

        return $this->render('fleet_transfers/show', [
            'title' => $transfer['reference'],
            'transfer' => $transfer,
            'checks' => $checks,
            'lastCheck' => $lastCheck,
            'approval' => $approval,
            'history' => $this->transfers->siteHistory((int) $transfer['equipment_id']),
            'canCheck' => ($awaitingControl || $transfer['status'] === 'authorized') && $user->canAt('fleet.check', $from),
            'canDepart' => $transfer['status'] === 'authorized' && $user->canAt('fleet.dispatch', $from),
            'blockedUnfit' => $transfer['status'] === 'authorized' && ($lastCheck === null || $lastCheck['conclusion'] === 'unfit'),
            'canReceive' => $transfer['status'] === 'departed' && $user->canAt('fleet.dispatch', $to) && (int) ($transfer['departed_by'] ?? 0) !== $user->id,
            'canCancel' => in_array($transfer['status'], ['requested', 'authorized'], true)
                && ((int) ($transfer['requested_by'] ?? 0) === $user->id || $user->canAt('approvals.override', $from) || $user->canAt('approvals.override', $to)),
            'carriers' => $this->references->options('carriers'),
            'document' => $this->document($transfer, $checks, $approval),
        ]);
    }

    public function check(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->recordCheck($id, $request->body, self::files($request, 'photos'));

        return $this->redirect("/fleet-transfers/$id", t('flash.fleet_check'));
    }

    public function depart(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->depart($id, $request->body);

        return $this->redirect("/fleet-transfers/$id", t('flash.fleet_departed'));
    }

    public function receive(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->receive($id, $request->body, self::files($request, 'photos'));

        return $this->redirect("/fleet-transfers/$id", t('flash.fleet_received'));
    }

    public function cancel(Request $request, array $params): Response
    {
        $id = $this->id($params);
        $this->service->cancel($id, (string) $request->string('cancel_reason'));

        return $this->redirect("/fleet-transfers/$id", t('flash.updated'));
    }

    /**
     * Fichiers envoyés sous « name[] » (plusieurs photos), remis sous forme de liste d'entrées simples.
     *
     * @return list<array>
     */
    private static function files(Request $request, string $key): array
    {
        $entry = $request->files[$key] ?? null;
        if (!is_array($entry) || !isset($entry['tmp_name'])) {
            return [];
        }
        if (!is_array($entry['tmp_name'])) {
            return ($entry['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$entry];
        }
        $files = [];
        foreach ($entry['tmp_name'] as $i => $tmp) {
            if (($entry['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = ['tmp_name' => $tmp, 'name' => $entry['name'][$i] ?? 'photo', 'size' => $entry['size'][$i] ?? 0, 'error' => $entry['error'][$i] ?? UPLOAD_ERR_OK];
        }

        return array_slice($files, 0, 8);
    }

    /**
     * Contenu du PDF : l'ordre de transfert (OTV) et sa check-list technique, sous le MÊME numéro.
     * Les libellés sont déjà traduits ; public/assets/js/pm-doc.js ne fait que la mise en page.
     */
    private function document(array $tr, array $checks, ?array $approval): array
    {
        $dash = static fn ($v): string => $v === null || $v === '' ? '—' : (string) $v;
        $dt = static fn (?string $v): string => $v ? format_date($v, true) : '—';
        $meters = static function ($hours, $km, $fuel): string {
            $parts = [];
            if ($hours !== null) {
                $parts[] = format_number($hours) . ' h';
            }
            if ($km !== null) {
                $parts[] = format_number($km) . ' km';
            }
            if ($fuel !== null) {
                $parts[] = $fuel . ' %';
            }

            return $parts === [] ? '—' : implode(' · ', $parts);
        };
        $acts = [];
        foreach ($approval['actions'] ?? [] as $action) {
            $acts[$action['step_code']] = $action['actor_name'] . ' — ' . t('approval_decision.' . $action['decision']) . ' — ' . format_date($action['acted_at'], true)
                . ((int) $action['is_override'] === 1 ? ' (' . t('approvals.override') . ')' : '');
        }
        $pre = null;
        $arrival = null;
        foreach ($checks as $check) {
            if ($check['kind'] === 'pre_departure') {
                $pre = $check;
            } else {
                $arrival = $check;
            }
        }
        $checkTable = static function (?array $check): array {
            $rows = [];
            foreach (FleetTransferService::ITEMS as $item) {
                $entry = $check['items'][$item] ?? null;
                $rows[] = [t('fleet_item.' . $item), $entry !== null ? t('fleet_result.' . $entry['result']) : '', $entry['note'] ?? ''];
            }

            return $rows;
        };
        $head = [t('fleet.item'), t('fleet.result'), t('fleet.item_note')];
        $identity = [
            [t('fleet.park_number'), $tr['equipment_code']],
            [t('fields.name'), $tr['equipment_name']],
            [t('fields.category_id'), $dash($tr['category'])],
            [t('fields.plate_number'), $tr['plate_number'] ?: t('fleet.not_registered')],
            [t('fields.chassis_number'), $dash($tr['chassis_number'] ?: $tr['serial_number'])],
        ];

        return [
            'reference' => $tr['reference'],
            'company' => 'VRSC SARL',
            'footer' => t('fleet.doc_footer', ['reference' => $tr['reference']]),
            'pages' => [
                [
                    'title' => t('fleet.otv') . ' ' . $tr['reference'],
                    'subtitle' => $tr['from_site'] . ' → ' . $tr['to_site'] . ' · ' . t('fleet_status.' . $tr['status']),
                    'sections' => [
                        ['title' => t('fleet.sec_equipment'), 'rows' => $identity],
                        ['title' => t('fleet.sec_transfer'), 'rows' => [
                            [t('fields.reason'), $tr['reason']],
                            [t('stock.from_site'), $tr['from_site']],
                            [t('stock.to_site'), $tr['to_site']],
                            [t('fleet.planned'), $dt($tr['planned_departure']) . ' → ' . $dt($tr['planned_arrival'])],
                            [t('fleet.actual'), $dt($tr['departed_at']) . ' → ' . $dt($tr['received_at'])],
                            [t('fleet.departure_meters'), $meters($tr['departure_hours'], $tr['departure_km'], $tr['departure_fuel'])],
                            [t('fleet.documents_refs'), $dash($tr['documents_refs'])],
                            [t('fields.observations'), $dash($tr['observations'])],
                        ]],
                        ['title' => t('fleet.sec_transport'), 'rows' => [
                            [t('fields.transport_mode'), t('fleet_mode.' . $tr['transport_mode'])],
                            [t('fleet.driver'), $dash(trim(($tr['driver_name'] ?? '') . ' ' . ($tr['driver_phone'] ?? '')))],
                            [t('fields.carrier_id'), $dash($tr['carrier'])],
                            [t('fields.convoy_leader'), $dash($tr['convoy_leader'])],
                            [t('fleet.tractor_trailer'), $dash(trim(($tr['tractor_plate'] ?? '') . ' / ' . ($tr['trailer_plate'] ?? ''), ' /'))],
                            [t('fields.transport_conditions'), $dash($tr['transport_conditions'])],
                        ]],
                        ['title' => t('fleet.sec_visas'), 'rows' => [
                            [t('fleet.visa_requester'), $dash($tr['requester']) . ' — ' . $dt($tr['requested_at'])],
                            [t('fleet.visa_opinion'), $acts['opinion'] ?? '—'],
                            [t('fleet.visa_control'), $acts['control'] ?? '—'],
                            [t('fleet.visa_authorization'), $acts['authorization'] ?? '—'],
                            [t('fleet.visa_departure'), $tr['departed_at'] ? $dash($tr['dispatcher']) . ' → ' . $dash($tr['handover_to']) . ' — ' . $dt($tr['departed_at']) : '—'],
                            [t('fleet.visa_arrival'), $tr['received_at'] ? $dash($tr['receiver']) . ' — ' . $dt($tr['received_at']) : '—'],
                        ]],
                    ],
                    'signatures' => [t('fleet.sign_requester'), t('fleet.sign_logistics'), t('fleet.sign_driver'), t('fleet.sign_receiver')],
                ],
                [
                    'title' => t('fleet.checklist') . ' ' . $tr['reference'],
                    'subtitle' => $tr['equipment_code'] . ' — ' . $tr['equipment_name'] . ' · ' . $tr['from_site'] . ' → ' . $tr['to_site'],
                    'sections' => [
                        ['title' => t('fleet.sec_check'), 'rows' => [
                            [t('fleet.checker'), $pre !== null ? $pre['checker_name'] . ' — ' . format_date($pre['checked_at'], true) : '—'],
                            [t('fleet.check_meters'), $pre !== null ? $meters($pre['hour_meter'], $pre['odometer_km'], null) : '—'],
                            [t('fields.conclusion'), $pre !== null ? t('fleet_conclusion.' . $pre['conclusion']) : t('fleet.conclusion_blank')],
                            [t('fleet.reserves'), $dash($pre['remarks'] ?? null)],
                        ]],
                    ],
                    'tables' => [
                        ['title' => t('fleet.sec_points'), 'head' => $head, 'body' => $checkTable($pre)],
                        ['title' => t('fleet.sec_arrival') . ($arrival !== null ? ' — ' . $arrival['checker_name'] . ' — ' . format_date($arrival['checked_at'], true)
                            . ' — ' . $meters($arrival['hour_meter'], $arrival['odometer_km'], $tr['arrival_fuel']) : ''), 'head' => $head, 'body' => $checkTable($arrival)],
                    ],
                    'notes' => $tr['arrival_note'] ? [t('fleet.arrival_gaps') . ' : ' . $tr['arrival_note']] : [],
                    'signatures' => [t('fleet.sign_checker'), t('fleet.sign_driver'), t('fleet.sign_receiver')],
                ],
            ],
        ];
    }
}
