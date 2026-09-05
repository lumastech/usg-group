import type { CycleMonthStatus, CycleStatus } from './enums';

/**
 * The signed-in user, as shared by HandleInertiaRequests on every page.
 *
 * `permissions` is flattened from the user's roles and drives what the UI offers.
 * It is a rendering hint only — the backend re-checks every action.
 */
export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    member_id: number | null;
    member_number: number | null;
    /** The member's own mobile number, used to prefill the provider's payment page. */
    phone?: string | null;
    roles: RoleName[];
    permissions: PermissionName[];
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};

/**
 * Any role a user may hold: one of the constitution's offices, or one the
 * administrator added on the roles screen. Custom roles carry no label of their own
 * here — only the offices below are named in the UI.
 */
export type RoleName = MemberRoleName | (string & {});

/** Mirrors App\Enums\MemberRole. */
export type MemberRoleName =
    | 'member'
    | 'treasurer'
    | 'vice_treasurer'
    | 'chairperson'
    | 'vice_chairperson'
    | 'admin';

/** Mirrors App\Enums\Permission. */
export type PermissionName =
    | 'members.view'
    | 'members.manage'
    | 'savings.view'
    | 'savings.record'
    | 'declarations.view'
    | 'declarations.submit-own'
    | 'declarations.record'
    | 'declarations.approve'
    | 'trading.operate'
    | 'loans.view'
    | 'loans.request'
    | 'loans.approve'
    | 'loans.disburse'
    | 'loans.record-repayment'
    | 'fund.view'
    | 'fund.record'
    | 'fund.approve-outflow'
    | 'payouts.approve'
    | 'payouts.execute'
    | 'payments.view'
    | 'payments.initiate'
    | 'payments.retry'
    | 'payments.reconcile'
    | 'governance.record'
    | 'reports.view'
    | 'audit.view'
    | 'cycles.manage'
    | 'cycles.calendar'
    | 'roles.manage';

/** Where in the month we are. Drives the dashboard banner and form availability. */
export type CycleWindow =
    'before_declarations' | 'declarations' | 'between' | 'trading' | 'closed';

export type CurrentCycleMonth = {
    id: number;
    sequence: number;
    label: string;
    status: CycleMonthStatus;
    declarations_open_at: string;
    declarations_close_at: string;
    trading_starts_on: string;
    trading_concludes_on: string;
    disbursement_on: string;
    declarations_open: boolean;
    trading_open: boolean;
    window: CycleWindow;
    /** Countdown to the next change of state, or null when nothing is pending. */
    seconds_remaining: number | null;
};

/** All money fields are integer ngwee. Format with formatMoney(). */
export type CurrentCycle = {
    id: number;
    name: string;
    status: CycleStatus;
    starts_on: string;
    ends_on: string;
    final_repayment_date: string;
    days_to_final_repayment: number;
    min_savings_ngwee: number;
    savings_increment_ngwee: number;
    lockdown_savings_cap_ngwee: number;
    is_lockdown: boolean;
    month: CurrentCycleMonth | null;
};

export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
