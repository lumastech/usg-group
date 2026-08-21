import type { CycleStatus, MemberStatus, PayoutCase } from './enums';
import type { MatrixMonth } from './savings';

/** The cycle summary every share-out screen carries in its header. */
export type ShareOutCycle = {
    id: number;
    name: string;
    status: CycleStatus;
    status_label: string;
    is_sharing_out: boolean;
};

/** One line of the pre-flight checklist. */
export type PreflightItem = {
    key: string;
    label: string;
    description: string;
    passed: boolean;
    outstanding_count: number;
    outstanding: {
        id: number;
        label: string;
        detail: string;
        href: string;
        amount_ngwee?: number;
    }[];
    href: string;
    verdict: string;
};

export type Preflight = {
    items: PreflightItem[];
    passed: boolean;
    blocking_count: number;
    checked_at: string;
};

/** One member's line on the SHARE OUT sheet. All money is integer ngwee. */
export type ShareOutRow = {
    member_id: number;
    member_number: number;
    full_name: string;
    status: MemberStatus;
    status_label: string;
    case: PayoutCase;
    case_label: string;
    is_diaspora: boolean;
    cells: Record<number, { savings: number; interest: number }>;
    total_savings_ngwee: number;
    total_interest_ngwee: number;
    outstanding_loan_ngwee: number;
    net_value_ngwee: number;
    round_off_ngwee: number;
    net_payable_ngwee: number;
    is_negative: boolean;
    settled: boolean;
    payout_id: number | null;
};

export type ShareOutSheet = {
    months: MatrixMonth[];
    rows: ShareOutRow[];
    totals: {
        months: Record<number, { savings: number; interest: number }>;
        total_savings_ngwee: number;
        total_interest_ngwee: number;
        outstanding_loan_ngwee: number;
        net_value_ngwee: number;
        round_off_ngwee: number;
        net_payable_ngwee: number;
        payable_ngwee: number;
        shortfall_ngwee: number;
        members: number;
        settled: number;
    };
};

/** What the batch runner would settle, and what it already has. */
export type ShareOutBatch = {
    candidates: {
        member_id: number;
        member_number: number;
        full_name: string;
        net_value_ngwee: number;
        round_off_ngwee: number;
        net_payable_ngwee: number;
        payable_ngwee: number;
        shortfall_ngwee: number;
        is_negative: boolean;
    }[];
    schedule: {
        rows: {
            payout_id: number;
            member_id: number;
            member_number: number | null;
            full_name: string | null;
            case: PayoutCase;
            case_label: string;
            net_value_ngwee: number;
            round_off_ngwee: number;
            amount_ngwee: number;
            executed_at: string | null;
        }[];
        total_ngwee: number;
        count: number;
    };
};

/** One month of a negative-net-value member's catch-up plan. */
export type RiskScheduleMonth = {
    month: number;
    opening_ngwee: number;
    interest_ngwee: number;
    repayment_ngwee: number;
    closing_ngwee: number;
};

export type RiskRow = {
    member_id: number;
    member_number: number;
    full_name: string;
    status: MemberStatus;
    status_label: string;
    net_value_ngwee: number;
    shortfall_ngwee: number;
    schedule: RiskScheduleMonth[];
    minimum_monthly_ngwee: number;
    total_repayable_ngwee: number;
    interest_ngwee: number;
    href: string;
};

export type RiskProjection = {
    rows: RiskRow[];
    totals: {
        members: number;
        shortfall_ngwee: number;
        minimum_monthly_ngwee: number;
        total_repayable_ngwee: number;
    };
    horizon_months: number;
    monthly_rate_bps: number;
};

/** A card on the reports hub. */
export type ReportCard = {
    key: string;
    title: string;
    description: string;
    permission: string;
    href: string;
    formats: string[];
    screen: string;
    takes_month?: boolean;
};

/** One figure the workbook holds that the ledgers may not. */
export type ImportEntry = {
    kind: string;
    member_id: number;
    member_name: string;
    member_number: number;
    cycle_month_id: number | null;
    month_label: string;
    amount_ngwee: number;
    already_posted: boolean;
    note: string | null;
    key: string;
    extra: Record<string, number>;
};

export type ImportPreview = {
    error?: string;
    entries: ImportEntry[];
    warnings: string[];
    workbook_totals: Record<string, number>;
    summary: Record<
        string,
        { planned: number; already_posted: number; amount_ngwee: number }
    >;
    reconciliation: {
        lines: {
            label: string;
            workbook_ngwee: number;
            ledger_ngwee: number;
            difference_ngwee: number;
            balanced: boolean;
            advisory: boolean;
            note: string;
        }[];
        balanced: boolean;
        discrepancy_count: number;
    };
};
