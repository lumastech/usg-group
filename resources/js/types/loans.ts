import type {
    CollateralClaimStatus,
    LoanScheduleItemStatus,
    LoanStatus,
    LoanTransactionType,
    MemberStatus,
} from './enums';

/**
 * One loan, as sent by LoanResource. All money is integer ngwee.
 *
 * `abilities` are the policy's own answers for the signed-in user, so the action bar
 * renders from them rather than re-deriving permissions on the client.
 */
export type Loan = {
    id: number;
    member_id: number;
    member_name?: string;
    member_number?: number;
    principal_ngwee: number;
    balance_ngwee: number;
    principal_outstanding_ngwee: number;
    interest_charged_ngwee: number;
    penalties_ngwee: number;
    tenor_months: number;
    schedule_compressed: boolean;
    status: LoanStatus;
    status_label: string;
    requested_at: string | null;
    approved_at: string | null;
    approved_by?: string | null;
    second_approver?: string | null;
    disbursed_at: string | null;
    disbursement_position: number | null;
    out_of_order_reason: string | null;
    settled_at: string | null;
    defaulted_at: string | null;
    discretion_override: boolean;
    discretion_note: string | null;
    rejection_reason: string | null;
    next_due_on: string | null;
    next_due_ngwee: number | null;
    days_late: number;
    abilities: LoanAbilities;
    [key: string]: unknown;
};

export type LoanAbilities = {
    view: boolean;
    approve: boolean;
    reject: boolean;
    disburse: boolean;
    recordRepayment: boolean;
    markDefault: boolean;
    claimCollateral: boolean;
};

/** One month of a schedule, carrying both the original and the current expectation. */
export type LoanScheduleItem = {
    id: number;
    sequence: number;
    month_label?: string;
    due_month: string;
    due_on: string;
    original_principal_ngwee: number;
    original_interest_ngwee: number;
    original_amount_due_ngwee: number;
    principal_due_ngwee: number;
    interest_due_ngwee: number;
    amount_due_ngwee: number;
    amount_paid_ngwee: number;
    outstanding_ngwee: number;
    paid_at: string | null;
    status: LoanScheduleItemStatus;
    status_label: string;
    [key: string]: unknown;
};

/** One line of the loan ledger. Append-only, so it carries no abilities. */
export type LoanTransaction = {
    id: number;
    type: LoanTransactionType;
    type_label: string;
    amount_ngwee: number;
    signed_amount_ngwee: number;
    balance_after_ngwee: number;
    principal_portion_ngwee: number;
    interest_portion_ngwee: number;
    penalty_portion_ngwee: number;
    occurred_on: string;
    notes: string | null;
    month_label?: string | null;
    recorded_by?: string | null;
    recorded_at: string | null;
    [key: string]: unknown;
};

export type CollateralItem = {
    description: string;
    estimated_value_ngwee: number;
};

export type CollateralClaim = {
    id: number;
    loan_id: number;
    status: CollateralClaimStatus;
    status_label: string;
    items: CollateralItem[];
    claimed_value_ngwee: number;
    outstanding_at_claim_ngwee: number;
    covers_outstanding: boolean;
    prepared_by?: string | null;
    second_signer?: string | null;
    signed_off_at: string | null;
    enforced_at: string | null;
    released_at: string | null;
    note: string | null;
    abilities: {
        signOff: boolean;
        enforce: boolean;
        release: boolean;
    };
};

/** One failed condition, in the words the server chose. */
export type EligibilityReason = {
    code: string;
    message: string;
};

/** The eligibility endpoint's contract, shared by the wizard and the member portal. */
export type LoanEligibility = {
    eligible: boolean;
    reasons: EligibilityReason[];
    principal_ngwee: number;
    cumulative_savings_ngwee: number;
    ceiling_ngwee: number;
    tenor_months: number | null;
    earned_tenor_months: number | null;
    compressed: boolean;
    months_available: number;
    lockdown: boolean;
    has_open_loan: boolean;
    overridden: boolean;
};

/** The cycle's lending rules, so a form can explain what the server will enforce. */
export type LoanRules = {
    max_loan_multiple: number;
    minimum_ngwee: number;
    monthly_interest_bps: number;
    final_repayment_date: string;
    lockdown_starts_month?: number;
    ceiling_ngwee?: number;
};

/** One member's line of the workbook's LOANS sheet. */
export type LoanMatrixRow = {
    member_id: number;
    member_number: number;
    full_name: string;
    status: MemberStatus;
    status_label: string;
    cells: Record<
        number,
        { borrowed: number; repaid: number; balance: number }
    >;
    borrowed_ngwee: number;
    interest_paid_ngwee: number;
    penalties_ngwee: number;
    balance_ngwee: number;
    [key: string]: unknown;
};

export type LoanMatrix = {
    months: {
        id: number;
        sequence: number;
        label: string;
        year: string;
        full_label: string;
        lockdown: boolean;
    }[];
    rows: LoanMatrixRow[];
    totals: {
        months: Record<
            number,
            { borrowed: number; repaid: number; balance: number }
        >;
        borrowed_ngwee: number;
        interest_paid_ngwee: number;
        penalties_ngwee: number;
        balance_ngwee: number;
    };
};

/** One member's progress against the cycle's borrowing target. */
export type BorrowingTargetRow = {
    member_id: number;
    member_number: number;
    full_name: string;
    status: MemberStatus;
    status_label: string;
    borrowed_ngwee: number;
    target_ngwee: number;
    balance_to_borrow_ngwee: number;
    progress_percent: number;
    under_target: boolean;
    [key: string]: unknown;
};
