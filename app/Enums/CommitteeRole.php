<?php

namespace App\Enums;

/**
 * The offices a member may hold for a term.
 *
 * Distinct from App\Enums\MemberRole, which is the portal's authorisation bundle:
 * this enum is the constitution's list of offices, and it includes Signatory, who
 * countersigns at the bank but is granted nothing inside the portal. Recording a
 * term grants the office's matching portal role for its duration; see
 * App\Domain\Governance\CommitteeRoleSync.
 */
enum CommitteeRole: string
{
    case Chairperson = 'chairperson';
    case ViceChairperson = 'vice_chairperson';
    case Treasurer = 'treasurer';
    case ViceTreasurer = 'vice_treasurer';
    case Signatory = 'signatory';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Chairperson => 'Chairperson',
            self::ViceChairperson => 'Vice-Chairperson',
            self::Treasurer => 'Treasurer',
            self::ViceTreasurer => 'Vice-Treasurer',
            self::Signatory => 'Signatory',
        };
    }

    /**
     * The portal role this office carries, or null when it carries none.
     *
     * A signatory's authority is at the bank, not in the system, so the office is
     * recorded and shown but grants no permissions.
     */
    public function portalRole(): ?MemberRole
    {
        return match ($this) {
            self::Chairperson => MemberRole::Chairperson,
            self::ViceChairperson => MemberRole::ViceChairperson,
            self::Treasurer => MemberRole::Treasurer,
            self::ViceTreasurer => MemberRole::ViceTreasurer,
            self::Signatory => null,
        };
    }

    /**
     * The office this one succeeds to when a new cycle's committee is proposed.
     *
     * The constitution moves each deputy up rather than starting from nothing. The
     * result is only ever a proposal — see App\Domain\Governance\SuccessionPlanner.
     */
    public function succeedsTo(): ?self
    {
        return match ($this) {
            self::ViceChairperson => self::Chairperson,
            self::ViceTreasurer => self::Treasurer,
            default => null,
        };
    }
}
