import type { CycleWindow } from './auth';
import type { CycleMonthStatus, DeclarationStatus } from './enums';

/**
 * A month with its window state resolved, as DeclarationWindow::payload() sends it.
 *
 * The same shape is shared on every page as `currentCycle.month`, so the banner in
 * the shell and the form on the declaration screen read from one contract.
 */
export type DeclarationMonth = {
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
    seconds_remaining: number | null;
};

/** One member's promise for one month. All money is integer ngwee. */
export type Declaration = {
    id: number;
    member_id: number;
    member_name?: string;
    member_number?: number;
    cycle_month_id: number;
    month_label?: string;
    month_sequence?: number;
    saving_amount_ngwee: number;
    loan_repayment_amount_ngwee: number;
    loan_requested_amount_ngwee: number;
    /** Signed: negative when the member leaves the table with money. */
    total_expected_payment_ngwee: number;
    submitted_at: string | null;
    is_late: boolean;
    status: DeclarationStatus;
    status_label: string;
    note: string | null;
    recorded_by?: string | null;
    abilities: { update: boolean };
};

/** The figures the declaration form opens with. */
export type DeclarationDefaults = {
    saving_amount_ngwee: number;
    loan_repayment_amount_ngwee: number;
    loan_requested_amount_ngwee: number;
};

/** The cycle's savings rules, so the form enforces what the server will. */
export type DeclarationRules = {
    minimum_ngwee: number;
    increment_ngwee: number;
    lockdown_cap_ngwee: number;
    lockdown_starts_month?: number;
    is_lockdown: boolean;
    savings_cap_ngwee?: number | null;
};

/** One line of the workbook's DECLARATIONS sheet. */
export type DeclarationSheetRow = {
    member_id: number;
    member_number: number;
    full_name: string;
    declared: boolean;
    declaration_id: number | null;
    saving_ngwee: number;
    repayment_ngwee: number;
    requested_ngwee: number;
    total_ngwee: number;
    submitted_at: string | null;
    is_late: boolean;
    status: DeclarationStatus | null;
    status_label: string;
};

export type DeclarationSheet = {
    rows: DeclarationSheetRow[];
    totals: {
        saving_ngwee: number;
        repayment_ngwee: number;
        requested_ngwee: number;
        total_ngwee: number;
    };
    declared_count: number;
    missing_count: number;
};

/** The answer LoanEligibilityService gives, as the request field renders it. */
export type DeclarationEligibility = {
    eligible: boolean;
    reasons: { code: string; message: string }[];
    ceiling_ngwee: number;
    cumulative_savings_ngwee: number;
    lockdown: boolean;
    has_open_loan: boolean;
};
