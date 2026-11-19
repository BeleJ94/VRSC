<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Informations d'identification suivies sur la fiche équipement.
 * Chacune est soit renseignée, soit déclarée « non applicable » (une remorque n'a pas de puissance),
 * soit « manquante » : l'absence d'une donnée n'est jamais confondue avec « sans objet ».
 */
final class EquipmentInfo
{
    /** Information suivie → colonne qui la porte. */
    public const FIELDS = [
        'plate_number' => 'plate_number',
        'chassis_number' => 'chassis_number',
        'serial_number' => 'serial_number',
        'year_made' => 'year_made',
        'power' => 'power_value',
        'capacity' => 'capacity_value',
        'color' => 'color',
        'commissioned_at' => 'commissioned_at',
        'acquired_at' => 'acquired_at',
        'owner_name' => 'owner_name',
    ];

    public const POWER_UNITS = ['hp', 'kw', 'kva'];
    public const CAPACITY_UNITS = ['t', 'kg', 'm3', 'l', 'seats'];

    /** @return list<string> informations déclarées non applicables (liste enregistrée « a,b,c ») */
    public static function notApplicable(?string $stored): array
    {
        return array_values(array_intersect(array_keys(self::FIELDS), explode(',', (string) $stored)));
    }

    /** @return array<string, string> information → filled | na | missing */
    public static function states(array $equipment): array
    {
        $na = self::notApplicable($equipment['not_applicable'] ?? null);
        $states = [];
        foreach (self::FIELDS as $field => $column) {
            $value = $equipment[$column] ?? null;
            $states[$field] = $value !== null && $value !== '' ? 'filled' : (in_array($field, $na, true) ? 'na' : 'missing');
        }

        return $states;
    }

    /** @return list<string> informations manquantes */
    public static function missing(array $equipment): array
    {
        return array_keys(self::states($equipment), 'missing', true);
    }

    /** Condition SQL « au moins une information manquante » (alias de la table equipment). */
    public static function missingSql(string $alias = 'e'): string
    {
        $parts = [];
        foreach (self::FIELDS as $field => $column) {
            $parts[] = "($alias.$column IS NULL AND FIND_IN_SET('$field', COALESCE($alias.not_applicable, '')) = 0)";
        }

        return '(' . implode(' OR ', $parts) . ')';
    }
}
