<?php

declare(strict_types=1);

namespace App\Domain;

enum EquipmentStatus: string
{
    case Available = 'available';
    case Breakdown = 'breakdown';
    case Maintenance = 'maintenance';
    case WaitingPart = 'waiting_part';
    case Immobilized = 'immobilized';
    /** Travaux terminés : l'engin attend la validation de sa remise en service. */
    case PendingRelease = 'pending_release';
    /** Hors service : retiré de l'exploitation, ne change d'état que par décision manuelle. */
    case OutOfService = 'out_of_service';

    /** Couleurs d'état imposées par la charte. */
    public function color(): string
    {
        return match ($this) {
            self::Available => '#1E7A4C',
            self::Breakdown => '#B42318',
            self::Maintenance => '#1F4E79',
            self::WaitingPart => '#B54708',
            self::Immobilized => '#8D9098',
            self::PendingRelease => '#6941C6',
            self::OutOfService => '#344054',
        };
    }

    public function labelKey(): string
    {
        return 'status.' . $this->value;
    }

    /** L'engin est-il utilisable en exploitation ? */
    public function isOperational(): bool
    {
        return $this === self::Available;
    }

    /** État posé par décision (administrative ou de retrait) : les ordres de travail ne le modifient pas. */
    public function isSticky(): bool
    {
        return $this === self::Immobilized || $this === self::OutOfService;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }

    /** États qu'un utilisateur peut poser à la main (« à valider » résulte toujours d'une demande de remise en service). */
    public static function manual(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $s): bool => $s !== self::PendingRelease));
    }
}
