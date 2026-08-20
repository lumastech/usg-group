import type { TradingSessionStatus } from './enums';

/** One row of the trading-day sheet, as TradingEntryResource sends it. */
export type TradingEntry = {
    id: number;
    member_id: number;
    member_name?: string;
    member_number?: number;
    declaration_id: number | null;
    declared?: {
        saving_amount_ngwee: number;
        loan_repayment_amount_ngwee: number;
        loan_requested_amount_ngwee: number;
        is_late: boolean;
    } | null;
    expected_in_ngwee: number;
    actual_in_ngwee: number;
    received_at: string | null;
    expected_out_ngwee: number;
    actual_out_ngwee: number;
    disbursed_at: string | null;
    /** Actual less expected: negative means the member fell short. */
    variance_ngwee: number;
    penalty_days: number;
    savings_portion_ngwee: number;
    repayment_portion_ngwee: number;
    is_received: boolean;
    is_disbursed: boolean;
};

export type TradingSession = {
    id: number;
    status: TradingSessionStatus;
    status_label: string;
    scheduled_conclude_date: string;
    concluded_at: string | null;
    concluded_by: string | null;
};

/** The day's running position, which the sticky footer reads. */
export type TradingTotals = {
    expected_in_ngwee: number;
    actual_in_ngwee: number;
    expected_out_ngwee: number;
    actual_out_ngwee: number;
    variance_ngwee: number;
    cash_position_ngwee: number;
    projected_position_ngwee: number;
    received_count: number;
    outstanding_count: number;
    entry_count: number;
};

/** What concluding would post, counted from the rows the conclusion will walk. */
export type TradingPreview = {
    month_label: string;
    deposits: { count: number; total_ngwee: number };
    repayments: { count: number; total_ngwee: number };
    interest: { count: number };
    missed_installments: { count: number; month_label: string | null };
    late_penalties: { count: number; days: number };
    unreceived: { count: number };
};
