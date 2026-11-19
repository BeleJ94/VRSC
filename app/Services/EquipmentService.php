<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Domain\EquipmentInfo;
use App\Domain\EquipmentStatus;
use App\Repositories\CatalogRepository;
use App\Repositories\EquipmentRepository;
use App\Security\Auth;

final class EquipmentService
{
    /** Propriété, bon de commande (PO), contrat de prestation, location. */
    public const OWNERSHIPS = ['owned', 'po', 'contract', 'rental'];

    public function __construct(
        private readonly Database $db,
        private readonly EquipmentRepository $equipment,
        private readonly CatalogRepository $catalog,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
        private readonly EquipmentReleaseService $releases,
    ) {
    }

    public static function rules(): array
    {
        return [
            'site_id' => 'required|int',
            'category_id' => 'int',
            'brand_id' => 'int',
            'model_id' => 'int',
            'code' => 'required|string|max:30',
            'name' => 'required|string|max:150',
            'brand' => 'string|max:80',
            'model' => 'string|max:80',
            'serial_number' => 'string|max:80',
            'chassis_number' => 'string|max:60',
            'plate_number' => 'string|max:30',
            'odometer_km' => 'int|min:0|max:9999999',
            'ownership' => 'in:' . implode(',', self::OWNERSHIPS),
            'contract_ref' => 'string|max:60',
            'contract_party' => 'string|max:120',
            'contract_start' => 'date',
            'contract_end' => 'date',
            'assignment' => 'string|max:150',
            'commissioned_at' => 'date',
            'hour_meter' => 'int|min:0|max:999999',
            'meter_basis' => 'in:hours,km',
            'fuel_gauge_id' => 'int',
            'tank_capacity_l' => 'int|min:1|max:20000',
            'notes' => 'string|max:5000',
            'project_id' => 'int',
            'year_made' => 'int|min:1950|max:2100',
            'power_value' => 'numeric|min:0.1|max:99999',
            'power_unit' => 'in:' . implode(',', EquipmentInfo::POWER_UNITS),
            'capacity_value' => 'numeric|min:0.01|max:9999999',
            'capacity_unit' => 'in:' . implode(',', EquipmentInfo::CAPACITY_UNITS),
            'color' => 'string|max:40',
            'acquired_at' => 'date',
            'owner_name' => 'string|max:120',
            'fuel_reference' => 'numeric|min:0.01|max:9999',
        ];
    }

    public function create(array $input): int
    {
        $data = Validator::validate($input, self::rules());
        $data['code'] = mb_strtoupper($data['code']);
        $data = $this->resolveCatalog($data);
        $data = $this->normalizeIdentity($data, $input);
        $this->equipment->scope()->assertAllows($data['site_id']);
        if ($this->equipment->codeExists($data['code'])) {
            throw Validator::fail('code', 'unique');
        }

        return $this->db->transaction(function () use ($data): int {
            $id = $this->equipment->create($data);
            $this->db->execute('INSERT INTO equipment_site_history (equipment_id, site_id, started_at) VALUES (:e, :s, NOW())', ['e' => $id, 's' => $data['site_id']]);
            $this->equipment->addStatusHistory($id, null, EquipmentStatus::Available->value, null, null, $this->auth->user()?->id);
            $this->logger->log('create', 'equipment', $id, $data['site_id'], null, $data, $data['code'] . ' – ' . $data['name']);

            return $id;
        });
    }

    public function update(int $id, array $input): void
    {
        $data = Validator::validate($input, self::rules());
        $data['code'] = mb_strtoupper($data['code']);
        $data = $this->resolveCatalog($data);
        $data = $this->normalizeIdentity($data, $input);
        $this->equipment->scope()->assertAllows($data['site_id']);
        if ($this->equipment->codeExists($data['code'], $id)) {
            throw Validator::fail('code', 'unique');
        }

        $this->db->transaction(function () use ($id, $data): void {
            $before = $this->equipment->findForUpdate($id) ?? throw HttpException::notFound();
            if ((int) $before['site_id'] !== (int) $data['site_id']) {
                // Le changement d'affectation passe par un ordre de transfert réceptionné à destination.
                throw Validator::fail('site_id', 'site_via_transfer');
            }
            $this->equipment->update($id, $data);
            $after = $this->equipment->findForUpdate($id);
            $this->logger->log('update', 'equipment', $id, $data['site_id'], $before, $after, $data['code']);
        });
    }

    /** Changement d'état manuel (ex. immobilisation réglementaire), motif obligatoire. */
    /**
     * @return string 'changed', ou 'pending' quand le retour à « disponible » est soumis à validation
     */
    public function changeStatus(int $id, array $input): string
    {
        $data = Validator::validate($input, [
            'status' => 'required|in:' . implode(',', array_map(static fn (EquipmentStatus $s): string => $s->value, EquipmentStatus::manual())),
            'reason' => 'required|string|max:255',
        ]);

        return $this->db->transaction(function () use ($id, $data): string {
            $row = $this->equipment->findForUpdate($id) ?? throw HttpException::notFound();
            $target = EquipmentStatus::from($data['status']);
            if ($target === EquipmentStatus::Available && $row['status'] !== EquipmentStatus::Available->value) {
                // Remise en service : jamais directe, toujours validée par une autre personne.
                if ($this->releases->pending($id) !== null) {
                    throw Validator::fail('status', 'release_pending');
                }
                $this->releases->open($row, null, $data['reason']);
                $this->applyStatus($row, EquipmentStatus::PendingRelease, $data['reason'], null);

                return 'pending';
            }
            $this->releases->cancelPending($id, $data['reason']);
            $this->applyStatus($row, $target, $data['reason'], null);

            return 'changed';
        });
    }

    /**
     * Applique un nouvel état (historique + journal). À appeler dans une transaction,
     * avec la ligne obtenue par findForUpdate().
     */
    public function applyStatus(array $equipmentRow, EquipmentStatus $status, ?string $reason, ?int $workOrderId): bool
    {
        if ($equipmentRow['status'] === $status->value) {
            return false;
        }
        $id = (int) $equipmentRow['id'];
        $this->equipment->updateStatus($id, $status->value);
        $this->equipment->addStatusHistory($id, $equipmentRow['status'], $status->value, $reason, $workOrderId, $this->auth->user()?->id);
        $this->logger->log(
            'status',
            'equipment',
            $id,
            (int) $equipmentRow['site_id'],
            ['status' => $equipmentRow['status']],
            ['status' => $status->value, 'reason' => $reason, 'work_order_id' => $workOrderId],
            $equipmentRow['code'] . ' : ' . $equipmentRow['status'] . ' → ' . $status->value
        );

        return true;
    }

    /**
     * Plaque et châssis en majuscules ; contrat cohérent (dates ordonnées, vidé pour un engin en propriété) ;
     * unités de puissance / capacité ; projet du même site ; informations déclarées « non applicables ».
     */
    private function normalizeIdentity(array $data, array $input): array
    {
        if ($data['power_value'] === null) {
            $data['power_unit'] = null;
        } else {
            $data['power_unit'] ??= 'hp';
        }
        if ($data['capacity_value'] === null) {
            $data['capacity_unit'] = null;
        } else {
            $data['capacity_unit'] ??= 't';
        }
        if ($data['acquired_at'] !== null && $data['acquired_at'] > date('Y-m-d')) {
            throw Validator::fail('acquired_at', 'stock_future');
        }
        if ($data['project_id'] !== null) {
            $project = $this->db->fetchOne('SELECT site_id FROM projects WHERE id = :id', ['id' => $data['project_id']]);
            if ($project === null || (int) $project['site_id'] !== (int) $data['site_id']) {
                throw Validator::fail('project_id', 'project_site');
            }
        }
        // « Non applicable » ne vaut que pour une information laissée vide.
        $declared = is_array($input['not_applicable'] ?? null) ? array_map('strval', $input['not_applicable']) : [];
        $na = [];
        foreach (EquipmentInfo::FIELDS as $field => $column) {
            if (in_array($field, $declared, true) && $data[$column] === null) {
                $na[] = $field;
            }
        }
        $data['not_applicable'] = $na !== [] ? implode(',', $na) : null;
        foreach (['plate_number', 'chassis_number'] as $key) {
            if ($data[$key] !== null) {
                $data[$key] = mb_strtoupper($data[$key]);
            }
        }
        $data['ownership'] ??= 'owned';
        if ($data['ownership'] === 'owned') {
            $data['contract_ref'] = $data['contract_party'] = $data['contract_start'] = $data['contract_end'] = null;
        }
        if ($data['contract_start'] !== null && $data['contract_end'] !== null && $data['contract_end'] < $data['contract_start']) {
            throw Validator::fail('contract_end', 'date_order');
        }

        return $data;
    }

    /**
     * Marque / modèle du référentiel : le modèle impose sa marque (et sa catégorie si non choisie) ;
     * les libellés texte brand/model sont recopiés pour l'affichage, la recherche et les exports.
     */
    private function resolveCatalog(array $data): array
    {
        if ($data['model_id'] !== null) {
            $model = $this->catalog->findModel($data['model_id']) ?? throw Validator::fail('model_id', 'exists');
            $data['brand_id'] = (int) $model['brand_id'];
            $data['category_id'] ??= $model['category_id'] !== null ? (int) $model['category_id'] : null;
            $data['model'] = $model['name'];
        }
        if ($data['brand_id'] !== null) {
            $brand = $this->catalog->findBrand($data['brand_id']) ?? throw Validator::fail('brand_id', 'exists');
            $data['brand'] = $brand['name'];
        }
        if ($data['category_id'] !== null && $this->catalog->findCategory($data['category_id']) === null) {
            throw Validator::fail('category_id', 'exists');
        }

        return $data;
    }

    public function archive(int $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $row = $this->equipment->findForUpdate($id) ?? throw HttpException::notFound();
            if ((int) $row['is_archived'] === 1) {
                return;
            }
            $this->equipment->setArchived($id, true);
            $this->logger->log('archive', 'equipment', $id, (int) $row['site_id'], ['is_archived' => 0], ['is_archived' => 1], $row['code']);
        });
    }
}
