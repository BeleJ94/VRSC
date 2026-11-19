<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Domain\ReferenceCatalog;

/**
 * Accès générique aux référentiels décrits par ReferenceCatalog. Les noms de tables et de colonnes viennent
 * exclusivement de ce catalogue (fixés dans le code) ; les référentiels rattachés à un site passent par SiteScope.
 */
final class ReferenceRepository extends Repository
{
    public const PER_PAGE = 50;

    /**
     * @return array{rows: list<array>, total: int, page: int, pages: int}
     */
    public function paginateList(string $type, string $search = '', ?int $siteId = null, int $page = 1, bool $activeOnly = false): array
    {
        $def = ReferenceCatalog::get($type);
        [$select, $joins] = $this->labels($def);
        [$where, $params] = $this->baseWhere($def);
        if ($search !== '') {
            [$searchSql, $searchParams] = $this->searchClause(array_map(static fn (string $c): string => "t.$c", $def['search']), $search);
            $where[] = $searchSql;
            $params += $searchParams;
        }
        if ($siteId !== null && $def['site_scoped']) {
            $where[] = 't.site_id = :site';
            $params['site'] = $siteId;
        }
        if ($activeOnly) {
            $where[] = 't.is_active = 1';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue("SELECT COUNT(*) FROM {$def['table']} t WHERE $whereSql", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($pages, $page));
        $rows = $this->db->fetchAll(
            sprintf(
                'SELECT t.*%s FROM %s t %s WHERE %s ORDER BY t.is_active DESC, t.code LIMIT %d OFFSET %d',
                $select,
                $def['table'],
                $joins,
                $whereSql,
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE
            ),
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /** Éléments actifs pour une liste déroulante : [id => « CODE – Nom »]. */
    public function options(string $type, ?int $siteId = null): array
    {
        $def = ReferenceCatalog::get($type);
        [$where, $params] = $this->baseWhere($def);
        $where[] = 't.is_active = 1';
        if ($siteId !== null && $def['site_scoped']) {
            $where[] = 't.site_id = :site';
            $params['site'] = $siteId;
        }
        $options = [];
        foreach ($this->db->fetchAll(
            "SELECT t.id, t.code, t.name FROM {$def['table']} t WHERE " . implode(' AND ', $where) . ' ORDER BY t.code',
            $params
        ) as $row) {
            $options[(int) $row['id']] = $row['code'] . ' – ' . $row['name'];
        }

        return $options;
    }

    public function find(string $type, int $id): ?array
    {
        $def = ReferenceCatalog::get($type);
        [$where, $params] = $this->baseWhere($def);

        return $this->db->fetchOne(
            "SELECT t.* FROM {$def['table']} t WHERE t.id = :id AND " . implode(' AND ', $where),
            ['id' => $id] + $params
        );
    }

    public function findByCode(string $type, string $code): ?array
    {
        $def = ReferenceCatalog::get($type);

        return $this->db->fetchOne("SELECT t.* FROM {$def['table']} t WHERE t.code = :code", ['code' => $code]);
    }

    public function codeExists(string $type, string $code, ?int $exceptId = null): bool
    {
        $def = ReferenceCatalog::get($type);

        return (bool) $this->db->fetchValue(
            "SELECT 1 FROM {$def['table']} WHERE code = :code AND id <> :id",
            ['code' => $code, 'id' => $exceptId ?? 0]
        );
    }

    public function create(string $type, array $data): int
    {
        $def = ReferenceCatalog::get($type);
        $columns = array_keys($def['fields']);
        $this->assertSite($def, $data);

        return $this->db->insert(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $def['table'],
                implode(', ', $columns),
                implode(', ', array_map(static fn (string $c): string => ":$c", $columns))
            ),
            self::params($def, $data)
        );
    }

    public function update(string $type, int $id, array $data): void
    {
        $def = ReferenceCatalog::get($type);
        $this->assertSite($def, $data);
        $this->db->execute(
            sprintf(
                'UPDATE %s SET %s WHERE id = :id',
                $def['table'],
                implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($def['fields'])))
            ),
            self::params($def, $data) + ['id' => $id]
        );
    }

    /** @return array{0: list<string>, 1: array<string, int>} */
    private function baseWhere(array $def): array
    {
        if (!$def['site_scoped']) {
            return [['1=1'], []];
        }
        [$scopeSql, $params] = $this->scope->clause('t.site_id');

        return [[$scopeSql], $params];
    }

    /** Libellés des clés étrangères : « site_id_label », « cost_center_id_label ». */
    private function labels(array $def): array
    {
        $select = '';
        $joins = '';
        foreach ($def['fields'] as $name => $field) {
            if ($field[0] === 'site') {
                $select .= ", rs.name AS {$name}_label";
                $joins .= " LEFT JOIN sites rs ON rs.id = t.$name";
            } elseif ($field[0] === 'ref') {
                $table = ReferenceCatalog::get($field[2]['ref'])['table'];
                $alias = 'r_' . $name;
                $select .= ", CONCAT($alias.code, ' – ', $alias.name) AS {$name}_label";
                $joins .= " LEFT JOIN $table $alias ON $alias.id = t.$name";
            }
        }

        return [$select, $joins];
    }

    private function assertSite(array $def, array $data): void
    {
        if ($def['site_scoped']) {
            $this->scope->assertAllows(isset($data['site_id']) ? (int) $data['site_id'] : null);
        }
    }

    private static function params(array $def, array $data): array
    {
        $params = [];
        foreach ($def['fields'] as $name => $field) {
            $value = $data[$name] ?? null;
            $params[$name] = $field[0] === 'bool' ? (!empty($value) ? 1 : 0) : $value;
        }

        return $params;
    }
}
