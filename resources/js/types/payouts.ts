import type {
    CycleStatus,
    GrantClaimStatus,
    MemberStatus,
    PayoutCase,
    PayoutLineKind,
    SettlementStatus,
} from './enums';

/** One line of a payout statement, as App\Domain\Payouts\PayoutLine shapes it. */
export type PayoutLine = {
    label: string;
    /** How the figure was arrived at, shown under the label on the statement. */
    formula: string;
    amount_ngwee: number;
    kind: PayoutLineKind;
};

/** A computed position: what a member is owed and how that was reached. */
export type PayoutBreakdown = {
    case: PayoutCase;
    member_id: number;
    lines: PayoutLine[];
    net_value_ngwee: number;
    round_off_ngwee: number;
    net_payable_ngwee: number;
    /** Never negative — a shortfall is recorded, not paid. */
    payable_ngwee: number;
    shortfall_ngwee: number;
    is_negative: boolean;
    /** The day interest stopped, for a death. */
    interest_cutoff: string | null;
    computed_at: string;
};

/** A member's row on the closures register. */
export type ClosureRow = {
    member_id: number;
    member_number: number;
    full_name: string;
    status: MemberStatus;
    status_label: string;
    case: PayoutCase;
    case_label: string;
    date_of_death: string | null;
    status_effective_on: string | null;
    net_value_ngwee: number;
    round_off_ngwee: number;
    net_payable_ngwee: number;
    is_negative: boolean;
    settled: boolean;
    settled_at: string | null;
    funeral_grant: {
        id: number;
        status: GrantClaimStatus;
        status_label: string;
        amount_ngwee: number;
    } | null;
};

export type ClosureCycle = {
    id: number;
    name: string;
    status: CycleStatus;
    status_label: string;
    is_sharing_out: boolean;
};

export type ClosureMember = {
    id: number;
    member_number: number;
    full_name: string;
    phone: string | null;
    status: MemberStatus;
    status_label: string;
    status_reason: string | null;
    status_effective_on: string | null;
    date_of_death: string | null;
    ledgers_frozen_at: string | null;
};

export type ExecutedPayout = {
    id: number;
    amount_ngwee: number;
    executed_at: string | null;
    executed_by: string | null;
    second_approver: string | null;
    early_settlement_note: string | null;
    note: string | null;
};

export type MemberDebt = {
    id: number;
    amount_owed_ngwee: number;
    status: SettlementStatus;
    status_label: string;
    note: string | null;
};

export type RepaymentArrangement = {
    id: number;
    amount_owed_ngwee: number;
    agreed_terms: string;
    agreed_on: string | null;
    status: SettlementStatus;
    status_label: string;
    next_of_kin: string | null;
    note: string | null;
};

export type NextOfKinOption = {
    id: number;
    name: string;
    phone: string | null;
    relationship_label: string;
};
