<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\I18n;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\PartRepository;
use App\Security\Auth;

/**
 * Grand livre du stock (cahier des charges § 5). Chaque mouvement verrouille la ligne de stock :
 * pas de stock négatif, pas de course. Un mouvement est daté, valorisé au coût moyen pondéré du magasin,
 * et refusé s'il tombe dans une période clôturée. Rien n'est jamais modifié ni supprimé :
 * une erreur se corrige par un nouveau mouvement.
 */
final class StockService
{
    /** Entrées : ancienne « entrée », stock initial, réception, retour, réception de transfert. */
    public const INBOUND = ['in', 'opening', 'receipt', 'return', 'transfer_in'];
    /** Sorties : consommation avec bénéficiaire, expédition de transfert. */
    public const OUTBOUND = ['out', 'transfer_out'];
    /** Écart d'inventaire approuvé (quantité signée) ; « adjust » = ancien ajustement (stock compté), usage interne. */
    public const SIGNED = ['inventory', 'adjust'];

    /** Mouvements saisissables à l'écran par le magasinier. Un écart passe obligatoirement par un inventaire approuvé. */
    public const MANUAL = ['receipt', 'opening', 'return', 'out'];

    public function __construct(
        private readonly Database $db,
        private readonly PartRepository $parts,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
        private readonly NumberingService $numbering,
    ) {
    }

    /**
     * @param string $type     voir INBOUND / OUTBOUND / SIGNED ; pour « adjust », $quantity est le nouveau stock compté ;
     *                         pour « inventory », $quantity est l'écart signé
     * @param array{moved_on?: ?string, unit_cost?: ?float, document_ref?: ?string, project_id?: ?int, department_id?: ?int,
     *              supplier_id?: ?int, transfer_id?: ?int, inventory_id?: ?int, reserved?: float} $options
     *        reserved = part de la sortie déjà réservée pour ce besoin (elle ne bute pas sur le « disponible »)
     * @return float stock après mouvement
     */
    public function move(int $partId, int $siteId, string $type, float $quantity, ?string $comment = null, ?int $workOrderId = null, ?int $equipmentId = null, array $options = []): float
    {
        if (!in_array($type, array_merge(self::INBOUND, self::OUTBOUND, self::SIGNED), true)) {
            throw new \InvalidArgumentException("Type de mouvement inconnu : $type");
        }
        $signed = in_array($type, self::SIGNED, true);
        if ((!$signed && $quantity <= 0) || ($type === 'adjust' && $quantity < 0)) {
            throw ValidationException::withMessage('quantity', I18n::current()->get('validation.positive'));
        }
        $this->parts->scope()->assertAllows($siteId);
        $part = $this->parts->find($partId) ?? throw HttpException::notFound();
        $movedOn = $options['moved_on'] ?? date('Y-m-d');
        if ($movedOn > date('Y-m-d')) {
            throw ValidationException::withMessage('moved_on', I18n::current()->get('validation.stock_future'));
        }

        return $this->db->transaction(function () use ($part, $partId, $siteId, $type, $quantity, $comment, $workOrderId, $equipmentId, $options, $movedOn, $signed): float {
            $this->assertOpen($siteId, $movedOn);
            $stock = $this->parts->lockStock($partId, $siteId);
            $before = (float) $stock['quantity'];
            $avg = $stock['avg_cost'] !== null ? (float) $stock['avg_cost'] : null;
            $delta = match (true) {
                in_array($type, self::INBOUND, true) => $quantity,
                in_array($type, self::OUTBOUND, true) => -$quantity,
                $type === 'adjust' => $quantity - $before,
                default => $quantity,
            };
            $after = round($before + $delta, 2);
            $unit = ['available' => self::plain($before), 'unit' => $part['unit']];
            if ($after < 0) {
                throw ValidationException::withMessage('quantity', I18n::current()->get('stock.insufficient', $unit));
            }
            // Une sortie ne puise pas dans ce qui est réservé à un autre besoin.
            if (in_array($type, self::OUTBOUND, true)) {
                $available = $before - max(0.0, (float) $stock['reserved'] - (float) ($options['reserved'] ?? 0));
                if ($quantity > $available + 0.0001) {
                    throw ValidationException::withMessage('quantity', I18n::current()->get('stock.reserved', ['available' => self::plain(max(0, $available)), 'unit' => $part['unit']]));
                }
            }

            // Valorisation : coût moyen pondéré du magasin ; à défaut, coût du catalogue ; sinon « manquant » (jamais zéro).
            $fallback = (float) $part['unit_cost'] > 0 ? (float) $part['unit_cost'] : null;
            $cost = $avg ?? $fallback;
            $newAvg = $avg;
            if ($delta > 0) {
                $given = isset($options['unit_cost']) ? (float) $options['unit_cost'] : null;
                if ($given !== null) {
                    $base = $avg ?? $fallback;
                    $newAvg = $before > 0 && $base !== null ? ($before * $base + $delta * $given) / ($before + $delta) : $given;
                    $cost = $given;
                } elseif ($avg === null && $fallback !== null) {
                    $newAvg = $fallback;
                }
            }

            $this->parts->setStock($partId, $siteId, $after, $newAvg !== null ? round($newAvg, 4) : null);
            $this->parts->addMovement($partId, $siteId, $type, $signed ? $delta : $quantity, $after, $workOrderId, $comment, $this->auth->user()?->id, $equipmentId, [
                'delta' => $delta,
                'moved_on' => $movedOn,
                'unit_cost' => $cost,
                'value' => $cost !== null ? round($delta * $cost, 2) : null,
                'document_ref' => $options['document_ref'] ?? null,
                'project_id' => $options['project_id'] ?? null,
                'department_id' => $options['department_id'] ?? null,
                'supplier_id' => $options['supplier_id'] ?? null,
                'transfer_id' => $options['transfer_id'] ?? null,
                'inventory_id' => $options['inventory_id'] ?? null,
            ]);
            $this->logger->log(
                'stock_' . $type,
                'part',
                $partId,
                $siteId,
                ['quantity' => $before],
                ['quantity' => $after, 'work_order_id' => $workOrderId, 'comment' => $comment, 'document_ref' => $options['document_ref'] ?? null],
                $part['reference'] . ' : ' . $before . ' → ' . $after
            );

            return $after;
        });
    }

    /** Coût unitaire de valorisation d'une sortie (null = donnée manquante). */
    public function costOf(int $partId, int $siteId): ?float
    {
        $avg = $this->db->fetchValue('SELECT avg_cost FROM part_stocks WHERE part_id = :p AND site_id = :s', ['p' => $partId, 's' => $siteId]);
        if ($avg !== null) {
            return (float) $avg;
        }
        $catalog = (float) ($this->parts->find($partId)['unit_cost'] ?? 0);

        return $catalog > 0 ? $catalog : null;
    }

    /**
     * Mouvement saisi à l'écran : réception, stock initial, retour, ou sortie avec bénéficiaire.
     * Un bon numéroté (BR / BS) est émis pour les réceptions et les sorties.
     */
    public function moveFromInput(int $partId, array $input): float
    {
        // « in » : ancien libellé d'entrée, traité comme une réception.
        if (($input['type'] ?? null) === 'in') {
            $input['type'] = 'receipt';
        }
        $data = Validator::validate($input, [
            'site_id' => 'required|int',
            'type' => 'required|in:' . implode(',', self::MANUAL),
            'quantity' => 'required|numeric|min:0.01|max:1000000',
            'unit_cost' => 'numeric|min:0|max:100000000',
            'moved_on' => 'date',
            'comment' => 'string|max:255',
            'equipment_id' => 'int',
            'project_id' => 'int',
            'department_id' => 'int',
            'supplier_id' => 'int',
        ]);
        if ($data['type'] === 'out') {
            // Toute sortie est imputée à un bénéficiaire : équipement, projet ou service.
            if ($data['equipment_id'] === null && $data['project_id'] === null && $data['department_id'] === null) {
                throw Validator::fail('equipment_id', 'beneficiary_required');
            }
            $data['unit_cost'] = null;
        } else {
            $data['equipment_id'] = $data['project_id'] = $data['department_id'] = null;
        }
        if ($data['type'] !== 'receipt') {
            $data['supplier_id'] = null;
        }
        foreach (['equipment_id' => 'equipment', 'project_id' => 'projects', 'department_id' => 'departments', 'supplier_id' => 'suppliers'] as $field => $table) {
            if ($data[$field] !== null && !$this->belongs($table, $data[$field], $data['site_id'])) {
                throw Validator::fail($field, 'exists');
            }
        }

        return $this->db->transaction(function () use ($partId, $data): float {
            $prefix = ['receipt' => 'BR', 'out' => 'BS', 'return' => 'BRT'][$data['type']] ?? null;

            return $this->move($partId, $data['site_id'], $data['type'], $data['quantity'], $data['comment'], null, $data['equipment_id'], [
                'moved_on' => $data['moved_on'],
                'unit_cost' => $data['unit_cost'],
                'document_ref' => $prefix !== null ? $this->numbering->next($prefix) : null,
                'project_id' => $data['project_id'],
                'department_id' => $data['department_id'],
                'supplier_id' => $data['supplier_id'],
            ]);
        });
    }

    public function updateSettings(int $partId, array $input): void
    {
        $data = Validator::validate($input, [
            'site_id' => 'required|int',
            'min_quantity' => 'required|numeric|min:0|max:1000000',
            'location' => 'string|max:60',
        ]);
        $this->db->transaction(function () use ($partId, $data): void {
            $stock = $this->parts->lockStock($partId, $data['site_id']);
            $this->parts->setStockSettings($partId, $data['site_id'], $data['min_quantity'], $data['location']);
            $this->logger->log(
                'stock_settings',
                'part',
                $partId,
                $data['site_id'],
                ['min_quantity' => $stock['min_quantity'], 'location' => $stock['location']],
                ['min_quantity' => $data['min_quantity'], 'location' => $data['location']],
            );
        });
    }

    // --- Réservations -----------------------------------------------------------

    /** Réserve une quantité disponible pour un besoin identifié (transfert autorisé, EDB…). */
    public function reserve(int $partId, int $siteId, float $quantity, string $refType, int $refId): void
    {
        $this->db->transaction(function () use ($partId, $siteId, $quantity, $refType, $refId): void {
            $part = $this->parts->find($partId) ?? throw HttpException::notFound();
            $stock = $this->parts->lockStock($partId, $siteId, false);
            $available = (float) $stock['quantity'] - (float) $stock['reserved'];
            if ($quantity <= 0 || $quantity > $available + 0.0001) {
                throw ValidationException::withMessage('quantity', I18n::current()->get('stock.reserved', ['available' => self::plain(max(0, $available)), 'unit' => $part['unit']]));
            }
            $this->db->execute(
                'INSERT INTO stock_reservations (part_id, site_id, quantity, ref_type, ref_id, created_by) VALUES (:p, :s, :q, :t, :r, :u)',
                ['p' => $partId, 's' => $siteId, 'q' => $quantity, 't' => $refType, 'r' => $refId, 'u' => $this->auth->user()?->id]
            );
            $this->db->execute('UPDATE part_stocks SET reserved = reserved + :q WHERE part_id = :p AND site_id = :s', ['q' => $quantity, 'p' => $partId, 's' => $siteId]);
        });
    }

    /**
     * Lève les réservations actives d'un besoin (consommées par la sortie, ou libérées à l'annulation).
     *
     * @return array<int, float> quantité levée par article
     */
    public function releaseReservations(string $refType, int $refId, bool $consumed): array
    {
        return $this->db->transaction(function () use ($refType, $refId, $consumed): array {
            $released = [];
            $rows = $this->db->fetchAll(
                "SELECT * FROM stock_reservations WHERE ref_type = :t AND ref_id = :r AND status = 'active' FOR UPDATE",
                ['t' => $refType, 'r' => $refId]
            );
            foreach ($rows as $row) {
                $this->parts->lockStock((int) $row['part_id'], (int) $row['site_id'], false);
                $this->db->execute(
                    'UPDATE part_stocks SET reserved = GREATEST(0, reserved - :q) WHERE part_id = :p AND site_id = :s',
                    ['q' => $row['quantity'], 'p' => $row['part_id'], 's' => $row['site_id']]
                );
                $this->db->execute(
                    'UPDATE stock_reservations SET status = :st, closed_at = NOW() WHERE id = :id',
                    ['st' => $consumed ? 'consumed' : 'released', 'id' => $row['id']]
                );
                $released[(int) $row['part_id']] = ($released[(int) $row['part_id']] ?? 0) + (float) $row['quantity'];
            }

            return $released;
        });
    }

    // --- Périodes ---------------------------------------------------------------

    public function isClosed(int $siteId, string $date): bool
    {
        return (bool) $this->db->fetchValue(
            'SELECT 1 FROM stock_periods WHERE site_id = :s AND period = :p',
            ['s' => $siteId, 'p' => substr($date, 0, 7)]
        );
    }

    /** Une écriture datée dans une période clôturée est refusée : la régularisation se date dans la période ouverte. */
    public function assertOpen(int $siteId, string $date): void
    {
        if ($this->isClosed($siteId, $date)) {
            throw ValidationException::withMessage('moved_on', I18n::current()->get('validation.stock_period_closed', ['period' => substr($date, 0, 7)]));
        }
    }

    private function belongs(string $table, int $id, int $siteId): bool
    {
        $siteScoped = in_array($table, ['equipment', 'projects'], true);
        $sql = "SELECT 1 FROM $table WHERE id = :id" . ($siteScoped ? ' AND site_id = :site' : '');

        return (bool) $this->db->fetchValue($sql, ['id' => $id] + ($siteScoped ? ['site' => $siteId] : []));
    }

    private static function plain(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
