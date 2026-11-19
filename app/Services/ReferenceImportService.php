<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\I18n;
use App\Core\ValidationException;
use App\Domain\ReferenceCatalog;
use App\Repositories\ReferenceRepository;
use App\Repositories\SiteRepository;
use App\Security\Auth;

/**
 * Import initial contrôlé des fichiers bureautiques (cahier des charges § 2.1) pour les référentiels :
 * chaque ligne est validée comme à l'écran, les doublons (code déjà présent en base ou répété dans le fichier)
 * sont écartés, et chaque import laisse un journal (qui, quand, combien, rapport ligne par ligne).
 *
 * Le fichier (Excel ou CSV) est lu dans le navigateur ; le serveur ne reçoit que des lignes et revalide tout.
 */
final class ReferenceImportService
{
    public const MAX_ROWS = 2000;

    public const NEW = 'new';
    public const DUPLICATE = 'duplicate';
    public const INVALID = 'invalid';

    public function __construct(
        private readonly Database $db,
        private readonly ReferenceRepository $references,
        private readonly ReferenceService $service,
        private readonly SiteRepository $sites,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /** Colonnes attendues : [champ => libellé traduit]. */
    public function columns(string $type): array
    {
        $columns = [];
        foreach (array_keys(ReferenceCatalog::get($type)['fields']) as $name) {
            $columns[$name] = t('fields.' . $name);
        }

        return $columns;
    }

    /**
     * Contrôle sans écriture.
     *
     * @param list<array<string, mixed>> $rows lignes du fichier, indexées par nom de champ ou par libellé de colonne
     * @return array{lines: list<array{line: int, code: string, name: string, status: string, errors: list<string>, data: array|null}>, counts: array<string, int>}
     */
    public function preview(string $type, array $rows): array
    {
        $def = ReferenceCatalog::get($type);
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessage('rows', I18n::current()->get('import.too_many', ['max' => self::MAX_ROWS]));
        }
        $aliases = $this->aliases($def);
        $seen = [];
        $lines = [];
        $counts = [self::NEW => 0, self::DUPLICATE => 0, self::INVALID => 0];
        foreach (array_values($rows) as $i => $raw) {
            $row = $this->normalize($def, is_array($raw) ? $raw : [], $aliases);
            if (implode('', array_map('strval', array_filter($row, 'is_scalar'))) === '') {
                continue; // ligne vide
            }
            $code = mb_strtoupper(trim((string) ($row['code'] ?? '')));
            $line = ['line' => $i + 2, 'code' => $code, 'name' => (string) ($row['name'] ?? ''), 'status' => self::NEW, 'errors' => [], 'data' => null];
            if ($code !== '' && (isset($seen[$code]) || $this->references->codeExists($type, $code))) {
                $line['status'] = self::DUPLICATE;
                $line['errors'][] = t(isset($seen[$code]) ? 'import.duplicate_file' : 'import.duplicate_db');
            } else {
                try {
                    $line['data'] = $this->service->prepare($type, $this->resolve($def, $row), null);
                    $seen[$code] = true;
                } catch (ValidationException $e) {
                    $line['status'] = self::INVALID;
                    $line['errors'] = array_values($e->errors);
                }
            }
            $counts[$line['status']]++;
            $lines[] = $line;
        }

        return ['lines' => $lines, 'counts' => $counts];
    }

    /**
     * Importe les lignes valides et nouvelles ; les autres sont consignées dans le journal d'import.
     *
     * @return array{log_id: int, counts: array<string, int>, lines: list<array>}
     */
    public function import(string $type, array $rows, ?string $fileName): array
    {
        return $this->db->transaction(function () use ($type, $rows, $fileName): array {
            $preview = $this->preview($type, $rows);
            foreach ($preview['lines'] as $line) {
                if ($line['status'] === self::NEW) {
                    $this->references->create($type, $line['data']);
                }
            }
            $report = array_map(static fn (array $l): array => array_diff_key($l, ['data' => true]), $preview['lines']);
            $logId = $this->db->insert(
                'INSERT INTO import_logs (target, file_name, rows_total, rows_created, rows_skipped, rows_invalid, report, imported_by)
                 VALUES (:target, :file, :total, :created, :skipped, :invalid, :report, :user)',
                [
                    'target' => ReferenceCatalog::get($type)['table'],
                    'file' => $fileName !== null ? mb_substr(basename($fileName), 0, 190) : null,
                    'total' => count($preview['lines']),
                    'created' => $preview['counts'][self::NEW],
                    'skipped' => $preview['counts'][self::DUPLICATE],
                    'invalid' => $preview['counts'][self::INVALID],
                    'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'user' => $this->auth->user()?->id,
                ]
            );
            $this->logger->log('import', ReferenceCatalog::entityType($type), $logId, null, null, $preview['counts'] + ['file' => $fileName],
                t(ReferenceCatalog::labelKey($type)));

            return ['log_id' => $logId, 'counts' => $preview['counts'], 'lines' => $report];
        });
    }

    /** @return list<array> derniers imports de ce référentiel */
    public function history(string $type, int $limit = 10): array
    {
        return $this->db->fetchAll(
            sprintf(
                "SELECT l.id, l.file_name, l.rows_total, l.rows_created, l.rows_skipped, l.rows_invalid, l.imported_at,
                        CONCAT(u.first_name, ' ', u.last_name) AS imported_by_name
                   FROM import_logs l LEFT JOIN users u ON u.id = l.imported_by
                  WHERE l.target = :t ORDER BY l.id DESC LIMIT %d",
                max(1, min(100, $limit))
            ),
            ['t' => ReferenceCatalog::get($type)['table']]
        );
    }

    /** En-têtes acceptés pour chaque champ : nom technique et libellés français / anglais (sans casse ni accents). */
    private function aliases(array $def): array
    {
        $aliases = [];
        $i18n = I18n::current();
        foreach (array_keys($def['fields']) as $name) {
            $aliases[self::key($name)] = $name;
            foreach (['fr', 'en'] as $locale) {
                $label = $i18n->catalogue($locale)['fields.' . $name] ?? null;
                if ($label !== null) {
                    $aliases[self::key($label)] ??= $name;
                }
            }
        }

        return $aliases;
    }

    private function normalize(array $def, array $raw, array $aliases): array
    {
        $row = [];
        foreach ($raw as $header => $value) {
            $name = $aliases[self::key((string) $header)] ?? null;
            if ($name !== null && is_scalar($value)) {
                $row[$name] = trim((string) $value);
            }
        }

        return $row;
    }

    /** Traduit les valeurs lisibles du fichier : site par code ou nom, référentiel lié par code, oui / non, dates. */
    private function resolve(array $def, array $row): array
    {
        foreach ($def['fields'] as $name => $field) {
            $value = $row[$name] ?? '';
            if ($field[0] === 'bool') {
                // Colonne absente ou vide : « actif » / « central » par défaut.
                $row[$name] = $value === '' ? '1' : (in_array(self::key($value), ['1', 'oui', 'yes', 'true', 'vrai', 'x', 'o', 'y'], true) ? '1' : '0');
                continue;
            }
            if ($value === '') {
                continue;
            }
            if ($field[0] === 'site') {
                $row[$name] = $this->siteId($value) ?? '0';
            } elseif ($field[0] === 'ref') {
                $ref = $this->references->findByCode($field[2]['ref'], mb_strtoupper(explode(' – ', $value)[0]));
                $row[$name] = $ref !== null ? (string) $ref['id'] : '0';
            } elseif ($field[0] === 'select') {
                foreach ($field[2]['options'] as $option) {
                    if (self::key($value) === self::key($option) || self::key($value) === self::key(t($field[2]['label'] . '.' . $option))) {
                        $row[$name] = $option;
                    }
                }
            } elseif ($field[0] === 'date' && preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $m)) {
                $row[$name] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            } elseif ($field[0] === 'date') {
                $row[$name] = substr($value, 0, 10);
            }
        }

        return $row;
    }

    private function siteId(string $value): ?string
    {
        foreach ($this->sites->listActive() as $site) {
            if (self::key($value) === self::key((string) $site['code']) || self::key($value) === self::key((string) $site['name'])) {
                return (string) $site['id'];
            }
        }

        return null;
    }

    /** Clé de comparaison : minuscules, sans accents ni ponctuation. */
    private static function key(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'û' => 'u', 'ù' => 'u', 'ç' => 'c']);

        return preg_replace('/[^a-z0-9]+/', '', $text) ?? '';
    }
}
