<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Rôles et permissions. Les permissions s'appliquent toujours DANS le périmètre de sites de l'utilisateur.
 */
enum Role: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Technician = 'technician';
    case Viewer = 'viewer';
    /** Agent de saisie : n'accède qu'à la feuille de saisie des index de ses sites. */
    case Encoder = 'encoder';
    // Fonctions VRSC (séparation demandeur / approbateur / réceptionnaire / payeur), attribuables par site.
    /** Demandeur : émet les états de besoins et les demandes de transfert. */
    case Requester = 'requester';
    /** Responsable hiérarchique : donne l'avis hiérarchique (acte distinct de l'autorisation). */
    case Supervisor = 'supervisor';
    /** Direction Logistique : autorise (ordres de transfert, sorties intersites, achats). */
    case Logistics = 'logistics';
    /** Magasinier : tient la fiche du magasin de son site, réceptionne. */
    case Storekeeper = 'storekeeper';
    /** Acheteur : devis, commandes. */
    case Buyer = 'buyer';
    /** Caissier : exécute les décaissements autorisés. */
    case Cashier = 'cashier';
    /** Finance : contrôle des pièces, engagements, rapprochements. */
    case Finance = 'finance';

    private const VRSC = ['requester', 'supervisor', 'logistics', 'storekeeper', 'buyer', 'cashier', 'finance'];

    private const PERMISSIONS = [
        'meters.entry' => ['admin', 'manager', 'technician', 'encoder'],
        'readings.manage' => ['admin'],
        'dashboard.view' => ['admin', 'manager', 'technician', 'viewer', ...self::VRSC],
        // Référentiels communs, délégations et circuit d'approbation
        'reference.view' => ['admin', 'manager', 'viewer', ...self::VRSC],
        'reference.manage' => ['admin', 'logistics'],
        'delegations.manage' => ['admin', 'logistics'],
        'approvals.use' => ['admin', 'manager', 'technician', ...self::VRSC],
        'approvals.override' => ['admin', 'logistics'],
        'attachments.view' => ['admin', 'manager', 'technician', 'viewer', ...self::VRSC],
        'equipment.view' => ['admin', 'manager', 'technician', 'viewer'],
        'equipment.manage' => ['admin', 'manager'],
        'equipment.status' => ['admin', 'manager', 'technician'],
        'equipment.reading' => ['admin', 'manager', 'technician'],
        'equipment.photo' => ['admin', 'manager', 'technician'],
        'workorder.view' => ['admin', 'manager', 'technician', 'viewer'],
        'workorder.create' => ['admin', 'manager', 'technician'],
        'workorder.execute' => ['admin', 'manager', 'technician'],
        'workorder.manage' => ['admin', 'manager'],
        'preventive.view' => ['admin', 'manager', 'technician', 'viewer'],
        'preventive.manage' => ['admin', 'manager'],
        'preventive.delete' => ['admin'],
        'parts.view' => ['admin', 'manager', 'technician', 'viewer', ...self::VRSC],
        // Stocks multisites : états, mouvements du magasinier, transferts, inventaires, clôture mensuelle
        'stock.view' => ['admin', 'manager', 'viewer', ...self::VRSC],
        'stock.move' => ['admin', 'manager', 'storekeeper'],
        'stock.transfer' => ['admin', 'manager', 'storekeeper', 'requester', 'supervisor', 'logistics'],
        'stock.inventory' => ['admin', 'manager', 'storekeeper'],
        'stock.close' => ['admin', 'logistics', 'finance'],
        // États de besoins (EDB) et achats : émission, service depuis le stock, devis et commandes
        'edb.view' => ['admin', 'manager', 'viewer', ...self::VRSC],
        'edb.create' => ['admin', 'manager', 'technician', 'requester', 'supervisor', 'storekeeper'],
        'purchase.manage' => ['admin', 'buyer', 'logistics'],
        'parts.manage' => ['admin', 'manager'],
        'field.use' => ['admin', 'manager', 'technician'],
        'personnel.view' => ['admin', 'manager', 'technician', 'viewer'],
        'personnel.manage' => ['admin', 'manager'],
        'equipment.assign' => ['admin', 'manager'],
        'documents.manage' => ['admin', 'manager'],
        'activity.view' => ['admin', 'manager'],
        'reports.view' => ['admin', 'manager', 'viewer'],
        'sites.manage' => ['admin'],
        'users.manage' => ['admin'],
        'settings.manage' => ['admin'],
    ];

    public function can(string $permission): bool
    {
        return in_array($this->value, self::PERMISSIONS[$permission] ?? [], true);
    }

    /** Page d'accueil après connexion. */
    public function homePath(): string
    {
        return match ($this) {
            self::Technician => '/terrain',
            self::Encoder => '/saisie',
            default => '/',
        };
    }

    public function labelKey(): string
    {
        return 'role.' . $this->value;
    }

    /** @return list<string> */
    public static function permissions(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
