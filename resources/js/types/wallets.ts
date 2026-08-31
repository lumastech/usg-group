import type { PayoutDestination } from '@/types/payments';

/**
 * Where a member's uncommitted money is standing.
 *
 * Not a savings account: savings are locked until share-out and this is not. The
 * wallet holds money topped up and not yet paid to the group, money the group has paid
 * the member and they have not yet withdrawn, and money a failed withdrawal put back.
 */
export type Wallet = {
    id: number;
    member_id: number | null;
    member_name?: string | null;
    member_number?: number | null;
    kind: 'member' | 'group';
    status: 'open' | 'frozen' | 'closed';
    status_label: string;
    balance_ngwee: number;
    opened_at: string | null;
    closed_at: string | null;
};

/** One line of a wallet statement. The sign is kept all the way to the screen. */
export type WalletEntry = {
    id: number;
    amount_ngwee: number;
    type:
        | 'top_up'
        | 'payment'
        | 'receipt'
        | 'withdrawal'
        | 'fee'
        | 'reversal'
        | 'carry_forward'
        | 'adjustment';
    type_label: string;
    source: string;
    is_credit: boolean;
    note: string | null;
    occurred_on: string | null;
    counterparty?: string | null;
    reverses_id: number | null;
    payment_reference?: string | null;
    created_at: string | null;
};

/** What the committee has set, and what the member may take out right now. */
export type WalletLimits = {
    top_up_min_ngwee: number;
    withdrawal_min_ngwee: number;
    withdrawal_fee_ngwee: number;
    available_ngwee: number;
};

export type { PayoutDestination };
