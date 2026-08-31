import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

import type { PaymentIntent, PayoutDestination } from '@/types/payments';
import type { Wallet as MemberWallet, WalletEntry } from '@/types/wallets';
import WalletPage from '../Wallet.vue';

const post = vi.fn();
const openIfStarted = vi.fn(() => false);
const verify = vi.fn();

// The page talks to the server through Inertia; stub it so the test stays in-process.
vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            errors: {},
            processing: false,
            post: (...args: unknown[]) => post(...args),
            reset: vi.fn(),
            transform(fn: (data: Record<string, unknown>) => unknown) {
                (this as Record<string, unknown>).__transform = fn;

                return this;
            },
        }),
}));

vi.mock('@/composables/usePaymentWidget', () => ({
    usePaymentWidget: () => ({
        error: { value: null },
        openIfStarted,
        verify,
    }),
}));

vi.mock('@/layouts/unity/MemberLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

function wallet(balance = 100_000): MemberWallet {
    return {
        id: 1,
        member_id: 9,
        kind: 'member',
        status: 'open',
        status_label: 'Open',
        balance_ngwee: balance,
        opened_at: '2025-12-01T00:00:00+02:00',
        closed_at: null,
    };
}

function destination(): PayoutDestination {
    return {
        id: 4,
        member_id: 9,
        type: 'mobile_money',
        type_label: 'Mobile money',
        label: 'Airtel Money …3571',
        masked_identifier: '…3571',
        bank_name: null,
        operator: 'airtel',
        operator_label: 'Airtel Money',
        resolved_account_name: 'Bertha Phiri',
        name_match_score: 100,
        name_matches: true,
        name_match_confirmed_at: null,
        is_default: true,
        is_verified: true,
        within_cooling_off: false,
        needs_second_signature: false,
        created_at: null,
        updated_at: null,
        abilities: {},
    } as PayoutDestination;
}

/** A top-up the member has started that has not reached the wallet yet. */
function topUp(overrides: Partial<PaymentIntent> = {}): PaymentIntent {
    return {
        id: 12,
        reference: 'usg-top-12-1',
        status: 'awaiting-authorization',
        member_status_label: 'Approve the prompt on your phone',
        status_reason: null,
        channel: 'mobile_money',
        channel_label: 'Mobile money',
        amount_ngwee: 50_000,
        has_stalled: false,
        is_posted: false,
        ...overrides,
    } as PaymentIntent;
}

function entry(overrides: Partial<WalletEntry> = {}): WalletEntry {
    return {
        id: 1,
        amount_ngwee: 100_000,
        type: 'top_up',
        type_label: 'Top-up',
        source: 'cash',
        is_credit: true,
        note: 'Counted at the January table',
        occurred_on: '2026-01-07',
        reverses_id: null,
        created_at: null,
        ...overrides,
    };
}

function render(props: Partial<Record<string, unknown>> = {}) {
    return mount(WalletPage, {
        props: {
            wallet: wallet(),
            statement: [entry()],
            destinations: [destination()],
            topUps: [],
            widget: null,
            limits: {
                top_up_min_ngwee: 100,
                withdrawal_min_ngwee: 5_000,
                withdrawal_fee_ngwee: 1_000,
                available_ngwee: 99_000,
            },
            phone: '0977433571',
            ...props,
        },
    });
}

describe('my/Wallet', () => {
    it('shows what the member is holding', () => {
        expect(render().text()).toContain('K1,000.00');
    });

    it('points a member with nowhere to send money at the destinations screen', () => {
        const page = render({
            destinations: [],
            limits: {
                top_up_min_ngwee: 100,
                withdrawal_min_ngwee: 5_000,
                withdrawal_fee_ngwee: 1_000,
                available_ngwee: 99_000,
            },
        });

        expect(page.text()).toContain('Nowhere to send it yet');
        expect(page.text()).not.toContain('Withdraw');
    });

    it('will not offer a withdrawal that is under the floor once the fee is allowed for', () => {
        const page = render({
            wallet: wallet(4_000),
            limits: {
                top_up_min_ngwee: 100,
                withdrawal_min_ngwee: 5_000,
                withdrawal_fee_ngwee: 1_000,
                available_ngwee: 3_000,
            },
        });

        const withdraw = page
            .findAll('button')
            .find((button) => button.text().includes('Withdraw'));

        expect(withdraw?.attributes('disabled')).toBeDefined();
    });

    /* A member who approves a prompt is quicker than the webhook and the poller both.
       An unchanged balance with nothing to press is what makes somebody pay twice. */
    it('shows a top-up still in flight and offers to check it', async () => {
        const page = render({ topUps: [topUp()] });

        expect(page.text()).toContain('Approve the prompt on your phone');

        const check = page
            .findAll('button')
            .find((button) => button.text().includes('Check the payment'));

        await check?.trigger('click');

        expect(verify).toHaveBeenCalledWith(12);
    });

    it('says plainly when a prompt was never approved', () => {
        const page = render({ topUps: [topUp({ has_stalled: true })] });

        expect(page.text()).toContain('That prompt was not approved in time');
        expect(page.text()).not.toContain('Approve the prompt on your phone');
    });

    it('starts a top-up on the rail the member picked', async () => {
        const page = render();

        /* The button stays dead until there is an amount — a top-up of nothing is not
           a payment the provider would take. */
        const prompt = page
            .findAll('button')
            .find((button) => button.text().includes('Prompt my phone'));

        expect(prompt?.attributes('disabled')).toBeDefined();

        await page.findAll('input[inputmode="decimal"]')[0].setValue('500');
        await page.findAll('input[inputmode="decimal"]')[0].trigger('input');

        await prompt?.trigger('click');

        expect(post).toHaveBeenCalledWith(
            '/my/wallet/top-up',
            expect.anything(),
        );
    });
});
