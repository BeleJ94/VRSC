<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Définition des référentiels communs (cahier des charges § 2.1). Un seul écran générique les gère tous :
 * table, champs, règles de validation et colonnes de liste sont fixés ici (jamais issus d'une saisie).
 *
 * Champ : [type, règle de validation, options]. Types : text, email, date, bool, select (liste fixe),
 * site (site du périmètre), ref (autre référentiel).
 */
final class ReferenceCatalog
{
    public const PROJECT_KINDS = ['project', 'worksite'];

    private const DEFINITIONS = [
        'projects' => [
            'table' => 'projects',
            'icon' => 'folder-kanban',
            'site_scoped' => true,
            'fields' => [
                'code' => ['text', 'required|string|max:20'],
                'name' => ['text', 'required|string|max:150'],
                'site_id' => ['site', 'required|int'],
                'project_kind' => ['select', 'required|in:project,worksite', ['options' => self::PROJECT_KINDS, 'label' => 'project_kind']],
                'cost_center_id' => ['ref', 'int', ['ref' => 'cost-centers']],
                'starts_on' => ['date', 'date'],
                'ends_on' => ['date', 'date'],
                'is_active' => ['bool', 'bool'],
            ],
            'list' => ['code', 'name', 'site_id', 'project_kind', 'cost_center_id', 'is_active'],
            'search' => ['code', 'name'],
        ],
        'warehouses' => [
            'table' => 'warehouses',
            'icon' => 'warehouse',
            'site_scoped' => true,
            'fields' => [
                'code' => ['text', 'required|string|max:20'],
                'name' => ['text', 'required|string|max:120'],
                'site_id' => ['site', 'required|int'],
                'location' => ['text', 'string|max:150'],
                'is_central' => ['bool', 'bool'],
                'is_active' => ['bool', 'bool'],
            ],
            'list' => ['code', 'name', 'site_id', 'location', 'is_central', 'is_active'],
            'search' => ['code', 'name', 'location'],
        ],
        'departments' => [
            'table' => 'departments',
            'icon' => 'network',
            'site_scoped' => false,
            'fields' => [
                'code' => ['text', 'required|string|max:20'],
                'name' => ['text', 'required|string|max:120'],
                'is_active' => ['bool', 'bool'],
            ],
            'list' => ['code', 'name', 'is_active'],
            'search' => ['code', 'name'],
        ],
        'cost-centers' => [
            'table' => 'cost_centers',
            'icon' => 'landmark',
            'site_scoped' => false,
            'fields' => [
                'code' => ['text', 'required|string|max:20'],
                'name' => ['text', 'required|string|max:120'],
                'is_active' => ['bool', 'bool'],
            ],
            'list' => ['code', 'name', 'is_active'],
            'search' => ['code', 'name'],
        ],
        'suppliers' => [
            'table' => 'suppliers',
            'icon' => 'store',
            'site_scoped' => false,
            'fields' => [
                'code' => ['text', 'required|string|max:20'],
                'name' => ['text', 'required|string|max:150'],
                'tax_id' => ['text', 'string|max:40'],
                'rccm' => ['text', 'string|max:60'],
                'contact_name' => ['text', 'string|max:120'],
                'phone' => ['text', 'string|max:30'],
                'email' => ['email', 'email|max:190'],
                'address' => ['text', 'string|max:255'],
                'is_active' => ['bool', 'bool'],
            ],
            'list' => ['code', 'name', 'tax_id', 'contact_name', 'phone', 'is_active'],
            'search' => ['code', 'name', 'tax_id', 'contact_name'],
        ],
        'carriers' => [
            'table' => 'carriers',
            'icon' => 'truck',
            'site_scoped' => false,
            'fields' => [
                'code' => ['text', 'required|string|max:20'],
                'name' => ['text', 'required|string|max:150'],
                'contact_name' => ['text', 'string|max:120'],
                'phone' => ['text', 'string|max:30'],
                'is_active' => ['bool', 'bool'],
            ],
            'list' => ['code', 'name', 'contact_name', 'phone', 'is_active'],
            'search' => ['code', 'name', 'contact_name'],
        ],
        'payment-methods' => [
            'table' => 'payment_methods',
            'icon' => 'credit-card',
            'site_scoped' => false,
            'fields' => [
                'code' => ['text', 'required|string|max:20'],
                'name' => ['text', 'required|string|max:120'],
                'is_active' => ['bool', 'bool'],
            ],
            'list' => ['code', 'name', 'is_active'],
            'search' => ['code', 'name'],
        ],
    ];

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function has(string $type): bool
    {
        return isset(self::DEFINITIONS[$type]);
    }

    /**
     * @return array{type: string, table: string, icon: string, site_scoped: bool, fields: array<string, array>, list: list<string>, search: list<string>}
     */
    public static function get(string $type): array
    {
        if (!self::has($type)) {
            throw new \InvalidArgumentException("Référentiel inconnu : $type");
        }

        return ['type' => $type] + self::DEFINITIONS[$type];
    }

    /** Clé i18n du nom d'un référentiel (« reference.type.suppliers »). */
    public static function labelKey(string $type): string
    {
        return 'reference.type.' . str_replace('-', '_', $type);
    }

    /** Type d'entité dans le journal d'activité (« ref_supplier »). */
    public static function entityType(string $type): string
    {
        return 'ref_' . self::DEFINITIONS[$type]['table'];
    }

    /** @return array<string, string> règles de validation par champ */
    public static function rules(string $type): array
    {
        return array_map(static fn (array $f): string => $f[1], self::get($type)['fields']);
    }
}
