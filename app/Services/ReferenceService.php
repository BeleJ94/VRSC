<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Domain\ReferenceCatalog;
use App\Repositories\ReferenceRepository;
use App\Repositories\SiteRepository;

/**
 * Création / modification des référentiels communs : code unique normalisé en majuscules, clés étrangères
 * contrôlées (site du périmètre, référentiel lié existant), journalisation avant / après.
 */
final class ReferenceService
{
    public function __construct(
        private readonly Database $db,
        private readonly ReferenceRepository $references,
        private readonly SiteRepository $sites,
        private readonly ActivityLogger $logger,
    ) {
    }

    public function create(string $type, array $input): int
    {
        $data = $this->prepare($type, $input, null);

        return $this->db->transaction(function () use ($type, $data): int {
            $id = $this->references->create($type, $data);
            $this->logger->log('create', ReferenceCatalog::entityType($type), $id, $data['site_id'] ?? null, null, $data, $data['code'] . ' – ' . $data['name']);

            return $id;
        });
    }

    public function update(string $type, int $id, array $input): void
    {
        $data = $this->prepare($type, $input, $id);
        $this->db->transaction(function () use ($type, $id, $data): void {
            $before = $this->references->find($type, $id) ?? throw HttpException::notFound();
            $this->references->update($type, $id, $data);
            $this->logger->log('update', ReferenceCatalog::entityType($type), $id, $data['site_id'] ?? null, $before, $data, $data['code'] . ' – ' . $data['name']);
        });
    }

    /**
     * Valide une ligne (écran ou import) sans l'écrire.
     *
     * @return array<string, mixed> données nettoyées
     */
    public function prepare(string $type, array $input, ?int $id): array
    {
        $def = ReferenceCatalog::get($type);
        $data = Validator::validate($input, ReferenceCatalog::rules($type));
        $data['code'] = mb_strtoupper((string) $data['code']);
        if ($this->references->codeExists($type, $data['code'], $id)) {
            throw Validator::fail('code', 'unique');
        }
        foreach ($def['fields'] as $name => $field) {
            if ($data[$name] === null) {
                continue;
            }
            if ($field[0] === 'site' && $this->sites->find((int) $data[$name]) === null) {
                throw Validator::fail($name, 'exists');
            }
            if ($field[0] === 'ref' && $this->references->find($field[2]['ref'], (int) $data[$name]) === null) {
                throw Validator::fail($name, 'exists');
            }
        }
        if (isset($data['starts_on'], $data['ends_on']) && $data['ends_on'] < $data['starts_on']) {
            throw Validator::fail('ends_on', 'period_order');
        }

        return $data;
    }
}
