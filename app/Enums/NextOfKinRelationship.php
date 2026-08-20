<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * How a next of kin relates to the member.
 *
 * The commitment sheets record free text ("Sister", "Aunt", "Mother"), so the
 * relationship is stored as one of these buckets alongside the original wording in
 * `relationship_label` — nothing the group wrote down is lost in the mapping.
 */
enum NextOfKinRelationship: string
{
    case Spouse = 'spouse';
    case Parent = 'parent';
    case Sibling = 'sibling';
    case Child = 'child';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $relationship): string => $relationship->value, self::cases());
    }

    /**
     * Bucket a free-text relationship from a commitment sheet or import file.
     *
     * Anything unrecognised becomes Other, which is why the caller should keep the
     * original wording as the label rather than discarding it.
     */
    public static function fromLabel(?string $label): self
    {
        return match (Str::of($label ?? '')->lower()->trim()->toString()) {
            'spouse', 'husband', 'wife', 'partner' => self::Spouse,
            'parent', 'mother', 'father', 'mum', 'mom', 'dad' => self::Parent,
            'sibling', 'sister', 'brother' => self::Sibling,
            'child', 'daughter', 'son' => self::Child,
            default => self::Other,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Spouse => 'Spouse',
            self::Parent => 'Parent',
            self::Sibling => 'Sibling',
            self::Child => 'Child',
            self::Other => 'Other',
        };
    }
}
