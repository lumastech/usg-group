<?php

namespace App\Enums;

/**
 * The roles a portal user may hold.
 *
 * Roles are only bundles of permissions; authorisation always checks a permission,
 * never a role. See App\Enums\Permission for the permissions each role receives.
 */
enum MemberRole: string
{
    case Member = 'member';
    case Treasurer = 'treasurer';
    case ViceTreasurer = 'vice_treasurer';
    case Chairperson = 'chairperson';
    case ViceChairperson = 'vice_chairperson';
    case Admin = 'admin';

    /**
     * Roles whose holders may act as one of the two required approvers.
     *
     * @return array<int, self>
     */
    public static function committee(): array
    {
        return [self::Treasurer, self::ViceTreasurer, self::Chairperson, self::ViceChairperson];
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    /** Human-readable office name, for display in the portal. */
    public function label(): string
    {
        return match ($this) {
            self::Member => 'Member',
            self::Treasurer => 'Treasurer',
            self::ViceTreasurer => 'Vice-Treasurer',
            self::Chairperson => 'Chairperson',
            self::ViceChairperson => 'Vice-Chairperson',
            self::Admin => 'Administrator',
        };
    }

    /** Whether holders of this role sit on the committee, admins included. */
    public function isCommittee(): bool
    {
        return $this === self::Admin || in_array($this, self::committee(), true);
    }

    /**
     * The permissions granted to this role.
     *
     * @return array<int, Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),

            self::Chairperson, self::ViceChairperson => [
                Permission::MembersManage,
                Permission::MembersView,
                Permission::LoansView,
                Permission::LoansRequest,
                Permission::LoansApprove,
                Permission::FundView,
                Permission::FundApproveOutflow,
                Permission::PayoutsApprove,
                // Oversight only: the chair watches the money move but does not push
                // it. Initiating and retrying belong to the treasury.
                Permission::PaymentsView,
                Permission::GovernanceRecord,
                Permission::ReportsView,
                // Re-dating a month is a decision taken at the table, so both the chair
                // and the treasury may make it. Opening and closing the cycle itself
                // (cycles.manage) stays with the administrator.
                Permission::CyclesCalendar,
                // The chair holds the group to account, so the audit trail is theirs
                // to read. The vice deputises for the chair in every other duty and
                // does here too.
                Permission::AuditView,
                Permission::SavingsView,
                Permission::DeclarationsView,
                Permission::DeclarationsSubmitOwn,
                // The "ask": accepting a member's declared figures is what turns the
                // request into something that may be collected. Chair and treasury
                // both hold it — whoever is at the table on the day.
                Permission::DeclarationsApprove,
            ],

            self::Treasurer, self::ViceTreasurer => [
                Permission::MembersView,
                Permission::LoansView,
                Permission::LoansRequest,
                Permission::LoansDisburse,
                Permission::LoansRecordRepayment,
                Permission::FundView,
                Permission::FundRecord,
                Permission::FundApproveOutflow,
                Permission::PayoutsExecute,
                Permission::SavingsView,
                Permission::SavingsRecord,
                Permission::DeclarationsView,
                Permission::DeclarationsSubmitOwn,
                Permission::DeclarationsRecord,
                Permission::DeclarationsApprove,
                Permission::TradingOperate,
                Permission::PaymentsView,
                Permission::PaymentsInitiate,
                Permission::PaymentsRetry,
                Permission::PaymentsReconcile,
                Permission::ReportsView,
                Permission::CyclesCalendar,
            ],

            self::Member => [
                Permission::DeclarationsSubmitOwn,
                Permission::LoansRequest,
            ],
        };
    }
}
