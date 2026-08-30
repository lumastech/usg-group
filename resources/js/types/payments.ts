import type {
    FeeBearer,
    MobileMoneyOperator,
    PaymentChannel,
    PaymentDirection,
    PaymentPurpose,
    PaymentStatus,
    PayoutDestinationType,
} from './enums';

/**
 * One payment, as the portal reads it. All money is integer ngwee — nothing here is
 * ever a float, and formatting happens in Vue.
 */
export type PaymentIntent = {
    id: number;
    reference: string;
    provider_reference: string | null;
    direction: PaymentDirection;
    direction_label: string;
    purpose: PaymentPurpose;
    purpose_label: string;
    channel: PaymentChannel;
    channel_label: string;
    status: PaymentStatus;
    status_label: string;
    /** Plain language for the member portal: "Approve the prompt on your phone". */
    member_status_label: string;
    status_reason: string | null;
    amount_ngwee: number;
    fee_ngwee: number | null;
    fee_bearer: FeeBearer | null;
    member_cost_ngwee: number;
    member_id: number | null;
    member_name?: string | null;
    member_number?: number | null;
    destination?: string | null;
    requested_by?: string | null;
    payable_type: string | null;
    payable_id: number | null;
    attempt: number;
    is_posted: boolean;
    /** An unanswered prompt the member may now give up on and try again. */
    has_stalled: boolean;
    initiated_at: string | null;
    completed_at: string | null;
    settled_at: string | null;
    posted_at: string | null;
    created_at: string | null;
    abilities: {
        view: boolean;
        retry: boolean;
        refresh: boolean;
        resolve: boolean;
    };
};

/** Where a member has asked to be paid. */
export type PayoutDestination = {
    id: number;
    member_id: number;
    type: PayoutDestinationType;
    type_label: string;
    label: string;
    masked_identifier: string;
    bank_name: string | null;
    operator: MobileMoneyOperator | null;
    operator_label: string | null;
    resolved_account_name: string | null;
    name_match_score: number | null;
    name_matches: boolean;
    name_match_confirmed_at: string | null;
    needs_name_confirmation: boolean;
    is_default: boolean;
    is_usable: boolean;
    /** Inside the cooling-off window, so paying to it needs a second signature. */
    is_new: boolean;
    verified_at: string | null;
    disabled_at: string | null;
    updated_at: string | null;
    abilities: {
        update: boolean;
        delete: boolean;
        confirmName: boolean;
    };
};

/** The figures on the committee payments screen. */
export type PaymentSummary = {
    in_flight: number;
    unposted: number;
    needs_attention: number;
    collected_ngwee: number;
    sent_ngwee: number;
    fees_ngwee: number;
};

/** What the browser needs to open the provider's hosted widget. */
export type PaymentWidgetConfig = {
    key: string;
    script: string;
    channels: string[];
};

/** One day's comparison of the provider's record against the group's. */
export type ReconciliationRun = {
    id: number;
    for_date: string;
    collections_count: number;
    collections_ngwee: number;
    transfers_count: number;
    transfers_ngwee: number;
    fees_ngwee: number;
    provider_balance_ngwee: number | null;
    unmatched: {
        side: string;
        reason: string;
        reference?: string | null;
        payment_intent_id?: number;
        amount_ngwee?: number | null;
        recorded_ngwee?: number;
    }[];
    unmatched_count: number;
    agrees: boolean;
    ran_at: string;
};

/** One line of the share-out payment run. */
export type ShareOutPaymentRow = {
    payout_id: number;
    member_id: number | null;
    member_number: number | null;
    full_name: string | null;
    amount_ngwee: number;
    destination: string | null;
    destination_id: number | null;
    account_name: string | null;
    name_match_score: number | null;
    needs_confirmation: boolean;
    by_hand: boolean;
};

export type ShareOutPaymentPreview = {
    rows: ShareOutPaymentRow[];
    payable_count: number;
    payable_ngwee: number;
    by_hand_count: number;
    by_hand_ngwee: number;
    balance_ngwee: number | null;
    shortfall_ngwee: number;
    can_run: boolean;
};
