<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Security\Auth;
use App\Security\SiteScope;

/**
 * Pièces jointes génériques (cahier des charges § 9) : justificatifs rattachés à une transaction, horodatés,
 * signés de leur auteur, versionnés. Un remplacement crée une nouvelle version ; l'ancienne reste consultable.
 * Les fichiers sont stockés hors racine web et servis après contrôle du périmètre.
 */
final class AttachmentService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private string $dir;

    public function __construct(
        private readonly Database $db,
        private readonly SiteScope $scope,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
        $this->dir = dirname(__DIR__, 2) . '/storage/uploads/attachments';
    }

    /** Répertoire de stockage (surchargé par les tests). */
    public function useDirectory(string $dir): void
    {
        $this->dir = rtrim($dir, '/\\');
    }

    /**
     * Joint un fichier à une transaction.
     *
     * @param array $file    entrée de $_FILES
     * @param bool  $replace true : nouvelle version de la pièce de même catégorie (l'ancienne est conservée)
     */
    public function add(string $entityType, int $entityId, ?int $siteId, array $file, string $category = 'document', ?string $note = null, bool $replace = false): int
    {
        if ($siteId !== null) {
            $this->scope->assertAllows($siteId);
        }
        $upload = $this->check($file);
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Stockage des pièces jointes indisponible');
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$upload['mime']];
        $target = $this->dir . '/' . $name;
        $moved = PHP_SAPI === 'cli' ? copy($upload['tmp'], $target) : move_uploaded_file($upload['tmp'], $target);
        if (!$moved) {
            throw new \RuntimeException('Enregistrement de la pièce jointe impossible');
        }
        try {
            return $this->db->transaction(function () use ($entityType, $entityId, $siteId, $category, $note, $replace, $upload, $name, $target): int {
                $version = 1;
                if ($replace) {
                    $version = 1 + (int) $this->db->fetchValue(
                        'SELECT COALESCE(MAX(version), 0) FROM attachments WHERE entity_type = :t AND entity_id = :e AND category = :c',
                        ['t' => $entityType, 'e' => $entityId, 'c' => $category]
                    );
                    $this->db->execute(
                        'UPDATE attachments SET is_current = 0 WHERE entity_type = :t AND entity_id = :e AND category = :c',
                        ['t' => $entityType, 'e' => $entityId, 'c' => $category]
                    );
                }
                $id = $this->db->insert(
                    'INSERT INTO attachments (entity_type, entity_id, site_id, category, version, file_name, original_name, mime, size_bytes, sha256, note, uploaded_by)
                     VALUES (:t, :e, :s, :c, :v, :f, :o, :m, :b, :h, :n, :u)',
                    [
                        't' => $entityType, 'e' => $entityId, 's' => $siteId, 'c' => $category, 'v' => $version,
                        'f' => $name, 'o' => $upload['original_name'], 'm' => $upload['mime'],
                        'b' => (int) filesize($target), 'h' => (string) hash_file('sha256', $target),
                        'n' => $note !== null ? mb_substr($note, 0, 255) : null, 'u' => $this->auth->user()?->id,
                    ]
                );
                $this->logger->log('attachment_add', $entityType, $entityId, $siteId, null,
                    ['category' => $category, 'file' => $upload['original_name'], 'version' => $version]);

                return $id;
            });
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }
    }

    /** @return list<array> pièces en vigueur d'une transaction ($category = null : toutes) */
    public function list(string $entityType, int $entityId, ?string $category = null, bool $withHistory = false): array
    {
        $sql = "SELECT a.id, a.category, a.version, a.is_current, a.original_name, a.mime, a.size_bytes, a.note, a.uploaded_at,
                       CONCAT(u.first_name, ' ', u.last_name) AS uploaded_by_name
                  FROM attachments a LEFT JOIN users u ON u.id = a.uploaded_by
                 WHERE a.entity_type = :t AND a.entity_id = :e";
        $params = ['t' => $entityType, 'e' => $entityId];
        if ($category !== null) {
            $sql .= ' AND a.category = :c';
            $params['c'] = $category;
        }
        if (!$withHistory) {
            $sql .= ' AND a.is_current = 1';
        }

        return $this->db->fetchAll($sql . ' ORDER BY a.category, a.version DESC, a.id DESC', $params);
    }

    public function has(string $entityType, int $entityId, ?string $category = null): bool
    {
        return $this->list($entityType, $entityId, $category) !== [];
    }

    /** Pièce obligatoire : refuse la suite de la transaction tant qu'aucun justificatif n'est joint. */
    public function assertPresent(string $entityType, int $entityId, string $category, string $field = 'attachment'): void
    {
        if (!$this->has($entityType, $entityId, $category)) {
            throw Validator::fail($field, 'attachment_required');
        }
    }

    /**
     * Fichier à servir (404 hors périmètre).
     *
     * @return array{path: string, mime: string, name: string}
     */
    public function file(int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM attachments WHERE id = :id', ['id' => $id]) ?? throw HttpException::notFound();
        if ($row['site_id'] !== null && !$this->scope->allows((int) $row['site_id'])) {
            throw HttpException::notFound();
        }
        $path = $this->dir . '/' . basename((string) $row['file_name']);
        if (!is_file($path)) {
            throw HttpException::notFound();
        }

        return ['path' => $path, 'mime' => (string) $row['mime'], 'name' => (string) $row['original_name']];
    }

    /** @return array{tmp: string, mime: string, original_name: string} */
    private function check(array $file): array
    {
        $max = ['max' => (string) (self::MAX_BYTES / 1024 / 1024)];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw Validator::fail('attachment', 'document_file_size', $max);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_file($tmp) || (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp))) {
            throw Validator::fail('attachment', 'document_file_type');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime]) || (str_starts_with($mime, 'image/') && @getimagesize($tmp) === false)) {
            throw Validator::fail('attachment', 'document_file_type');
        }

        return ['tmp' => $tmp, 'mime' => $mime, 'original_name' => mb_substr(basename((string) ($file['name'] ?? 'piece')), 0, 190) ?: 'piece'];
    }
}
