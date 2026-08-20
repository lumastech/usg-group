<?php

namespace App\Enums;

/**
 * Every granular ability in the system.
 *
 * Permissions are the currency of authorisation: policies and route middleware check
 * these, never a role. Roles (App\Enums\MemberRole) exist only to bundle them, so an
 * office can be re-scoped by editing MemberRole::permissions() and reseeding.
 */
enum Permission: string
{
    case MembersView = 'members.view';
    case MembersManage = 'members.manage';

    case SavingsView = 'savings.view';
    case SavingsRecord = 'savings.record';

    case DeclarationsView = 'declarations.view';
    case DeclarationsSubmitOwn = 'declarations.submit-own';
    case DeclarationsRecord = 'declarations.record';

    case TradingOperate = 'trading.operate';

    case LoansView = 'loans.view';
    case LoansRequest = 'loans.request';
    case LoansApprove = 'loans.approve';
    case LoansDisburse = 'loans.disburse';
    case LoansRecordRepayment = 'loans.record-repayment';

    case FundView = 'fund.view';
    case FundRecord = 'fund.record';
    case FundApproveOutflow = 'fund.approve-outflow';

    case PayoutsApprove = 'payouts.approve';
    case PayoutsExecute = 'payouts.execute';

    case GovernanceRecord = 'governance.record';

    case ReportsView = 'reports.view';

    case CyclesManage = 'cycles.manage';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $permission): string => $permission->value, self::cases());
    }

    /** The section of the portal this permission belongs to, e.g. "loans". */
    public function group(): string
    {
        return str($this->value)->before('.')->toString();
    }

    public function label(): string
    {
        return match ($this) {
            self::MembersView => 'View members',
            self::MembersManage => 'Manage members',
            self::SavingsView => 'View savings',
            self::SavingsRecord => 'Record savings',
            self::DeclarationsView => 'View declarations',
            self::DeclarationsSubmitOwn => 'Submit own declaration',
            self::DeclarationsRecord => 'Record declarations for members',
            self::TradingOperate => 'Operate the trading console',
            self::LoansView => 'View loans',
            self::LoansRequest => 'Request a loan',
            self::LoansApprove => 'Approve loans',
            self::LoansDisburse => 'Disburse loans',
            self::LoansRecordRepayment => 'Record loan repayments',
            self::FundView => 'View the social fund',
            self::FundRecord => 'Record social fund entries',
            self::FundApproveOutflow => 'Approve fund outflows',
            self::PayoutsApprove => 'Approve payouts',
            self::PayoutsExecute => 'Execute payouts',
            self::GovernanceRecord => 'Record governance decisions',
            self::ReportsView => 'View reports',
            self::CyclesManage => 'Manage cycles',
        };
    }
}
