import type {
    ApportionmentItemStatus,
    FuneralRelationship,
    GrantClaimStatus,
    SocialFundTransactionType,
} from './enums';

/** One line of the fund's ledger. All money is integer ngwee. */
export type FundEntry = {
    id: number;
    type: SocialFundTransactionType;
    type_label: string;
    amount_ngwee: number;
    is_outflow: boolean;
    occurred_on: string;
    member?: string | null;
    member_id: number | null;
    month_label?: string | null;
    recorded_by?: string | null;
    second_approver?: string | null;
    note: string | null;
    recorded_at: string | null;
};

export type FundMonth = {
    id: number;
    label: string;
    short_label: string;
    in_ngwee: number;
    out_ngwee: number;
    closing_ngwee: number;
};

/** Everything App\Domain\Reporting\SocialFundOverview computes for one cycle. */
export type FundOverview = {
    balance_ngwee: number;
    inflow_ngwee: number;
    outflow_ngwee: number;
    contribution_ngwee: number;
    expected_contribution_ngwee: number;
    contributions_paid: number;
    contributions_expected: number;
    months: FundMonth[];
    by_type: { type: string; label: string; total_ngwee: number }[];
};

export type UnpaidContribution = {
    member_id: number;
    member_number: number;
    full_name: string;
    phone: string | null;
    is_diaspora: boolean;
};

/** A funeral or unity baby claim, shaped identically for both tables. */
export type GrantClaim = {
    id: number;
    grant: 'funeral' | 'unity_baby';
    member?: string;
    member_id: number;
    detail: string;
    relationship: FuneralRelationship | null;
    relationship_label: string | null;
    born_on: string | null;
    claim_date: string;
    status: GrantClaimStatus;
    status_label: string;
    amount_ngwee: number;
    first_approver?: string | null;
    second_approver?: string | null;
    approved_at: string | null;
    paid_at: string | null;
    note: string | null;
    abilities: { approve?: boolean; pay?: boolean; reject?: boolean };
};

export type ApportionmentItem = {
    id: number;
    member: string | null;
    member_id: number;
    amount_ngwee: number;
    status: ApportionmentItemStatus;
    status_label: string;
    paid_on: string | null;
    reference: string | null;
    abilities: { confirmTransfer?: boolean };
};

export type Apportionment = {
    id: number;
    total_ngwee: number;
    apportioned_ngwee: number;
    share_ngwee: number;
    remainder_ngwee: number;
    declared_on: string;
    recorded_by?: string | null;
    second_approver?: string | null;
    note: string | null;
    items: ApportionmentItem[];
};

/** What DiasporaApportionmentService::preview returns, before anything is written. */
export type ApportionmentPreview = {
    total_ngwee: number;
    share_ngwee: number;
    remainder_ngwee: number;
    apportioned_ngwee: number;
    recipients: {
        member_id: number;
        member_number: number;
        full_name: string;
        amount_ngwee: number;
    }[];
};

export type FundRules = {
    contribution_ngwee: number;
    funeral_grant_ngwee: number;
    unity_baby_grant_ngwee: number;
};
