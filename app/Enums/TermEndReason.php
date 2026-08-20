<?php

namespace App\Enums;

/** Why a committee term stopped. */
enum TermEndReason: string
{
    case TermEnd = 'term_end';
    case Resigned = 'resigned';
    case Removed = 'removed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $reason): string => $reason->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::TermEnd => 'Term ended',
            self::Resigned => 'Resigned',
            self::Removed => 'Removed',
        };
    }
}
