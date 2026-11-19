<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Documents administratifs à échéance des engins (assurance, contrôle technique, vignette, stationnement…).
 * Périmètre : celui de l'engin. Le document « en vigueur » d'un type est celui dont l'échéance est la plus lointaine.
 */
final class EquipmentDocumentRepository extends Repository
{
    /** Condition « d est le document en vigueur de son type sur son engin » (alias d). */
    public const CURRENT = 'NOT EXISTS (SELECT 1 FROM equipment_documents n
        WHERE n.equipment_id = d.equipment_id AND n.document_type_id = d.document_type_id
          AND (n.expires_at > d.expires_at OR (n.expires_at = d.expires_at AND n.id > d.id)))';

    private const COLUMNS = 'd.id, d.equipment_id, d.document_type_id, d.issuer, d.reference, d.issued_at, d.expires_at, d.note,
        d.responsible_id, d.renewal_started_at, d.renewal_note,
        (SELECT CONCAT(ru.first_name, \' \', ru.last_name) FROM users ru WHERE ru.id = d.responsible_id) AS responsible,
        d.file_name, d.original_name, d.mime, d.size_bytes,
        d.created_at, t.code AS type_code, t.name AS type_name, t.alert_days, DATEDIFF(d.expires_at, CURDATE()) AS days_left';

    /** États du tableau de bord, du plus urgent au moins urgent. */
    public const STATES = ['expired', 'soon', 'renewing', 'missing', 'valid', 'none', 'not_applicable'];

    public const SORTABLE = [
        'code' => 'x.code',
        'site' => 'x.site',
        'type' => 'x.type_sort',
        'issuer' => 'x.issuer',
        'expires_at' => 'x.expires_at',
        'state' => 'x.state_rank',
    ];

    /**
     * Tableau de bord : une ligne par engin actif × type de document actif, avec le document en vigueur
     * (ou « manquant »). Requête dérivée x, filtrée par le périmètre de sites.
     *
     * @return array{0: string, 1: array}
     */
    private function boardSource(): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $sql = "(SELECT e.id AS equipment_id, e.code, e.name AS equipment_name, e.plate_number, e.site_id, s.name AS site,
                        t.id AS document_type_id, t.code AS type_code, t.name AS type_name, t.sort_order AS type_sort,
                        d.id, d.issuer, d.reference, d.issued_at, d.expires_at, d.file_name, d.renewal_started_at,
                        (SELECT CONCAT(ru.first_name, ' ', ru.last_name) FROM users ru WHERE ru.id = d.responsible_id) AS responsible, ex.reason AS exemption_reason,
                        DATEDIFF(d.expires_at, CURDATE()) AS days_left,
                        CASE WHEN d.id IS NULL AND ex.equipment_id IS NOT NULL THEN 'not_applicable'
                             WHEN d.id IS NULL THEN 'missing' WHEN d.renewal_started_at IS NOT NULL THEN 'renewing'
                             WHEN d.expires_at IS NULL THEN 'none'
                             WHEN d.expires_at < CURDATE() THEN 'expired'
                             WHEN d.expires_at <= CURDATE() + INTERVAL t.alert_days DAY THEN 'soon' ELSE 'valid' END AS state,
                        CASE WHEN d.id IS NULL AND ex.equipment_id IS NOT NULL THEN 6
                             WHEN d.id IS NULL THEN 3 WHEN d.renewal_started_at IS NOT NULL THEN 2
                             WHEN d.expires_at IS NULL THEN 5
                             WHEN d.expires_at < CURDATE() THEN 0
                             WHEN d.expires_at <= CURDATE() + INTERVAL t.alert_days DAY THEN 1 ELSE 4 END AS state_rank
                   FROM equipment e
                   JOIN sites s ON s.id = e.site_id
                   JOIN document_types t ON t.is_active = 1
                   LEFT JOIN equipment_documents d ON d.equipment_id = e.id AND d.document_type_id = t.id AND " . self::CURRENT . "
                   LEFT JOIN equipment_document_exemptions ex ON ex.equipment_id = e.id AND ex.document_type_id = t.id
                  WHERE e.is_archived = 0 AND $scopeSql) x";

        return [$sql, $params];
    }

    /** @return array{0: list<string>, 1: array} conditions des filtres site / type / état */
    private static function boardFilters(array $filters): array
    {
        $where = [];
        $params = [];
        if (!empty($filters['site_id'])) {
            $where[] = 'x.site_id = :f_site';
            $params['f_site'] = (int) $filters['site_id'];
        }
        if (!empty($filters['document_type_id'])) {
            $where[] = 'x.document_type_id = :f_type';
            $params['f_type'] = (int) $filters['document_type_id'];
        }
        if (!empty($filters['state'])) {
            $where[] = $filters['state'] === 'action' ? "x.state IN ('expired', 'soon')" : 'x.state = :f_state';
            if ($filters['state'] !== 'action') {
                $params['f_state'] = (string) $filters['state'];
            }
        }

        return [$where, $params];
    }

    public function datatable(\App\Core\DataTableRequest $dt, array $filters): array
    {
        [$from, $params] = $this->boardSource();
        [$where, $fp] = self::boardFilters($filters);
        $result = $this->paginate(
            $dt,
            'x.*',
            $from,
            [],
            $params,
            $where,
            $fp,
            ['x.code', 'x.equipment_name', 'x.plate_number', 'x.issuer', 'x.reference', 'x.site'],
        );
        foreach ($result['data'] as &$row) {
            $row['type_label'] = doc_type_label($row);
            $row['state_label'] = t('doc_state.' . $row['state'], ['days' => abs((int) $row['days_left'])]);
            if ($row['state'] === 'renewing' && $row['days_left'] !== null) {
                // Un renouvellement en cours ne masque pas l'échéance : elle reste lisible.
                $row['state_label'] .= ' · ' . ((int) $row['days_left'] < 0 ? t('doc_state.expired', ['days' => abs((int) $row['days_left'])]) : t('doc_state.soon', ['days' => (int) $row['days_left']]));
            }
        }

        return $result;
    }

    /** Nombre de lignes par état, pour les filtres site / type courants. @return array<string, int> */
    public function boardSummary(array $filters): array
    {
        [$from, $params] = $this->boardSource();
        [$where, $fp] = self::boardFilters(['state' => null] + $filters);
        $rows = $this->db->fetchAll(
            "SELECT x.state, COUNT(*) AS n FROM $from WHERE " . implode(' AND ', $where ?: ['1=1']) . ' GROUP BY x.state',
            $params + $fp
        );

        return array_map('intval', array_column($rows, 'n', 'state')) + array_fill_keys(self::STATES, 0);
    }

    /** État d'une échéance : renewing (renouvellement en cours), none (sans date), expired, soon (dans le délai d'alerte du type) ou valid. */
    public static function state(?string $expiresAt, int $alertDays, ?string $renewalStartedAt = null): string
    {
        if ($renewalStartedAt !== null && $renewalStartedAt !== '') {
            return 'renewing';
        }
        if ($expiresAt === null || $expiresAt === '') {
            return 'none';
        }
        $days = (int) floor((strtotime($expiresAt) - strtotime('today')) / 86400);

        return $days < 0 ? 'expired' : ($days <= $alertDays ? 'soon' : 'valid');
    }

    /** Types de documents actifs (+ $includeId pour conserver celui d'un document existant). */
    public function types(?int $includeId = null): array
    {
        return $this->db->fetchAll(
            'SELECT id, code, name, alert_days FROM document_types WHERE is_active = 1 OR id = :inc ORDER BY sort_order, name',
            ['inc' => $includeId ?? 0]
        );
    }

    public function find(int $id): ?array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');

        return $this->db->fetchOne(
            'SELECT ' . self::COLUMNS . ", e.site_id, e.code AS equipment_code
               FROM equipment_documents d
               JOIN document_types t ON t.id = d.document_type_id
               JOIN equipment e ON e.id = d.equipment_id
              WHERE d.id = :id AND $scopeSql",
            ['id' => $id] + $params
        );
    }

    /**
     * Documents d'un engin, regroupés par type : le premier de chaque groupe est celui en vigueur.
     *
     * @return list<array{type: array, current: ?array, history: list<array>}>
     */
    public function forEquipment(int $equipmentId): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $rows = $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . "
               FROM equipment_documents d
               JOIN document_types t ON t.id = d.document_type_id
               JOIN equipment e ON e.id = d.equipment_id
              WHERE d.equipment_id = :id AND $scopeSql
              ORDER BY d.expires_at IS NULL, d.expires_at DESC, d.id DESC",
            ['id' => $equipmentId] + $params
        );
        $groups = [];
        foreach ($this->types() as $type) {
            $groups[(int) $type['id']] = ['type' => $type, 'current' => null, 'history' => [], 'exemption' => null];
        }
        foreach ($this->db->fetchAll('SELECT document_type_id, reason FROM equipment_document_exemptions WHERE equipment_id = :id', ['id' => $equipmentId]) as $exemption) {
            if (isset($groups[(int) $exemption['document_type_id']])) {
                $groups[(int) $exemption['document_type_id']]['exemption'] = $exemption['reason'];
            }
        }
        foreach ($rows as $row) {
            $typeId = (int) $row['document_type_id'];
            $groups[$typeId] ??= ['type' => ['id' => $typeId, 'code' => $row['type_code'], 'name' => $row['type_name'], 'alert_days' => $row['alert_days']], 'current' => null, 'history' => [], 'exemption' => null];
            // Seul le document en vigueur peut être « en renouvellement ».
            $row['state'] = self::state($row['expires_at'], (int) $row['alert_days'], $groups[$typeId]['current'] === null ? $row['renewal_started_at'] : null);
            if ($groups[$typeId]['current'] === null) {
                $groups[$typeId]['current'] = $row;
            } else {
                $groups[$typeId]['history'][] = $row;
            }
        }

        return array_values($groups);
    }

    /**
     * Documents en vigueur échus ou dont l'échéance tombe dans le délai d'alerte de leur type.
     *
     * @param string|null $state 'expired' ou 'soon' pour ne garder qu'un état
     */
    public function expiring(?int $siteId = null, ?string $state = null): array
    {
        [$scopeSql, $params] = $this->scope->clause('e.site_id');
        $extra = '';
        if ($siteId !== null) {
            $extra .= ' AND e.site_id = :site';
            $params['site'] = $siteId;
        }
        if ($state === 'expired') {
            $extra .= ' AND d.expires_at < CURDATE()';
        } elseif ($state === 'soon') {
            $extra .= ' AND d.expires_at >= CURDATE()';
        }

        return $this->db->fetchAll(
            'SELECT ' . self::COLUMNS . ", e.code AS equipment_code, e.name AS equipment_name, e.plate_number, s.name AS site
               FROM equipment_documents d
               JOIN document_types t ON t.id = d.document_type_id AND t.is_active = 1
               JOIN equipment e ON e.id = d.equipment_id AND e.is_archived = 0
               JOIN sites s ON s.id = e.site_id
              WHERE d.expires_at IS NOT NULL AND d.expires_at <= CURDATE() + INTERVAL t.alert_days DAY
                AND " . self::CURRENT . " AND $scopeSql $extra
              ORDER BY d.expires_at, e.code",
            $params
        );
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO equipment_documents (equipment_id, document_type_id, issuer, reference, issued_at, expires_at, note, responsible_id, created_by)
             VALUES (:equipment_id, :document_type_id, :issuer, :reference, :issued_at, :expires_at, :note, :responsible_id, :created_by)',
            $data
        );
    }

    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE equipment_documents SET document_type_id = :document_type_id, issuer = :issuer, reference = :reference,
                    issued_at = :issued_at, expires_at = :expires_at, note = :note, responsible_id = :responsible_id
              WHERE id = :id',
            ['id' => $id] + $data
        );
    }

    /** @param array{file_name: string, original_name: ?string, mime: string, size_bytes: int}|null $file null = retrait du fichier */
    public function setFile(int $id, ?array $file): void
    {
        $this->db->execute(
            'UPDATE equipment_documents SET file_name = :file_name, original_name = :original_name, mime = :mime, size_bytes = :size_bytes WHERE id = :id',
            ['id' => $id] + ($file ?? ['file_name' => null, 'original_name' => null, 'mime' => null, 'size_bytes' => null])
        );
    }

    /** Début (date + responsable) ou fin (date nulle) du renouvellement d'un document. */
    public function setRenewal(int $id, ?string $startedAt, ?int $responsibleId, ?string $note): void
    {
        $this->db->execute(
            'UPDATE equipment_documents SET renewal_started_at = :s, renewal_note = :n, responsible_id = COALESCE(:r, responsible_id) WHERE id = :id',
            ['s' => $startedAt, 'n' => $note, 'r' => $responsibleId, 'id' => $id]
        );
    }

    public function exemption(int $equipmentId, int $typeId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM equipment_document_exemptions WHERE equipment_id = :e AND document_type_id = :t', ['e' => $equipmentId, 't' => $typeId]);
    }

    public function exempt(int $equipmentId, int $typeId, string $reason, ?int $userId): void
    {
        $this->db->execute(
            'INSERT INTO equipment_document_exemptions (equipment_id, document_type_id, reason, created_by) VALUES (:e, :t, :r, :u)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_by = VALUES(created_by)',
            ['e' => $equipmentId, 't' => $typeId, 'r' => $reason, 'u' => $userId]
        );
    }

    public function unexempt(int $equipmentId, int $typeId): void
    {
        $this->db->execute('DELETE FROM equipment_document_exemptions WHERE equipment_id = :e AND document_type_id = :t', ['e' => $equipmentId, 't' => $typeId]);
    }

    public function hasDocument(int $equipmentId, int $typeId): bool
    {
        return (bool) $this->db->fetchValue('SELECT 1 FROM equipment_documents WHERE equipment_id = :e AND document_type_id = :t LIMIT 1', ['e' => $equipmentId, 't' => $typeId]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM equipment_documents WHERE id = :id', ['id' => $id]);
    }
}
