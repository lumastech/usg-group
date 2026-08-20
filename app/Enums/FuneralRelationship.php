<?php

namespace App\Enums;

/**
 * Who a funeral grant may be claimed for.
 *
 * The constitution restricts the grant to a member's parent, spouse or child, and
 * allows no discretion — there is deliberately no Sibling or Other case here, so a
 * claim for anyone else cannot be represented at all, let alone approved.
 */
enum FuneralRelationship: string
{
    case Parent = 'parent';
    case Spouse = 'spouse';
    case Child = 'child';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $relationship): string => $relationship->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Parent => 'Parent',
            self::Spouse => 'Spouse',
            self::Child => 'Child',
        };
    }
}
