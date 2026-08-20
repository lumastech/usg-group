import type {
    MemberStatus,
    SavingsTransactionType,
    TransactionSource,
} from './enums';

/** A month column of the savings matrix. */
export type MatrixMonth = {
    id: number;
    sequence: number;
    label: string;
    year: string;
    full_label: string;
    lockdown: boolean;
};

/** One member's line across the matrix. All money is integer ngwee. */
export type MatrixRow = {
    member_id: number;
    member_number: number;
    full_name: string;
    status: MemberStatus;
    status_label: string;
    is_diaspora: boolean;
    cells: Record<number, { savings: number; interest: number }>;
    total_savings_ngwee: number;
    total_interest_ngwee: number;
    loan_balance_ngwee: number;
    net_value_ngwee: number;
};

export type SavingsMatrix = {
    months: MatrixMonth[];
    rows: MatrixRow[];
    totals: {
        months: Record<number, { savings: number; interest: number }>;
        total_savings_ngwee: number;
        total_interest_ngwee: number;
        loan_balance_ngwee: number;
        net_value_ngwee: number;
    };
};

/** One month of a member's own history, with the running totals behind it. */
export type SavingsMonth = {
    month_id: number;
    sequence: number;
    label: string;
    full_label: string;
    lockdown: boolean;
    savings_ngwee: number;
    interest_ngwee: number;
    cumulative_savings_ngwee: number;
    cumulative_interest_ngwee: number;
};

export type SavingsTotals = {
    savings_ngwee: number;
    interest_ngwee: number;
    loan_balance_ngwee: number;
    net_value_ngwee: number;
};

/** One line of the ledger, as sent by SavingsTransactionResource. */
export type SavingsTransaction = {
    id: number;
    type: SavingsTransactionType;
    source: TransactionSource;
    amount_ngwee: number;
    declared_amount_ngwee: number | null;
    variance_ngwee: number;
    occurred_on: string;
    note: string | null;
    month_label?: string;
    month_sequence?: number;
    recorded_by?: string | null;
    recorded_at: string | null;
};

/** The cycle's savings rules, so the entry form enforces what the server will. */
export type SavingsRules = {
    minimum_ngwee: number;
    increment_ngwee: number;
    lockdown_cap_ngwee: number;
    lockdown_starts_month: number;
};

export type SavingsMonthOption = {
    id: number;
    sequence: number;
    label: string;
    lockdown: boolean;
};
