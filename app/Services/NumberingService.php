<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Numérotation des documents métier (OTV, EDB, bons de sortie, commandes…) : « TYPE-AAAA-00001 ».
 * Le compteur est verrouillé le temps de la transaction : deux documents n'obtiennent jamais le même numéro
 * et un numéro émis n'est jamais réattribué.
 */
final class NumberingService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function next(string $docType, ?int $year = null): string
    {
        if (!preg_match('/^[A-Z][A-Z0-9]{1,19}$/', $docType)) {
            throw new \InvalidArgumentException("Type de document invalide : $docType");
        }
        $year ??= (int) date('Y');

        return $this->db->transaction(function () use ($docType, $year): string {
            $this->db->execute(
                'INSERT INTO document_sequences (doc_type, year, last_number) VALUES (:t, :y, 0)
                 ON DUPLICATE KEY UPDATE last_number = last_number',
                ['t' => $docType, 'y' => $year]
            );
            $last = (int) $this->db->fetchValue(
                'SELECT last_number FROM document_sequences WHERE doc_type = :t AND year = :y FOR UPDATE',
                ['t' => $docType, 'y' => $year]
            );
            $next = $last + 1;
            $this->db->execute(
                'UPDATE document_sequences SET last_number = :n WHERE doc_type = :t AND year = :y',
                ['n' => $next, 't' => $docType, 'y' => $year]
            );

            return sprintf('%s-%d-%05d', $docType, $year, $next);
        });
    }
}
