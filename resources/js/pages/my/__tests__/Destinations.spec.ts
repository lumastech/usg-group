import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

import Destinations from '../Destinations.vue';
import type { PayoutDestination } from '@/types/payments';

const post = vi.fn();
const put = vi.fn();
const destroy = vi.fn();
const reset = vi.fn();

// The page talks to the server through Inertia; stub it so the test stays in-process.
vi.mock('@inertiajs/vue3', () => ({
    router: {
        put: (...args: unknown[]) => put(...args),
        delete: (...args: unknown[]) => destroy(...args),
    },
    // Reactive, because the form's `type` is what swaps the bank and wallet fields.
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            errors: {},
            processing: false,
            post: (...args: unknown[]) => post(...args),
            reset,
        }),
}));

vi.mock('@/layouts/unity/MemberLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

function destination(
    overrides: Partial<PayoutDestination> = {},
): PayoutDestination {
    return {
        id: 1,
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
        needs_name_confirmation: false,
        is_default: true,
        is_usable: true,
        is_new: false,
        verified_at: '2026-01-01T00:00:00Z',
        disabled_at: null,
        updated_at: '2026-01-01T00:00:00Z',
        abilities: { update: true, delete: true, confirmName: false },
        ...overrides,
    } as PayoutDestination;
}

function mountPage(destinations: PayoutDestination[] = []) {
    return mount(Destinations, {
        props: {
            destinations: { data: destinations },
            banks: [{ value: '002', label: 'Absa Bank Zambia' }],
            operators: [{ value: 'airtel', label: 'Airtel Money' }],
            member: { id: 9, full_name: 'Bertha Phiri', phone: '0977433571' },
        },
        global: { stubs: { Link: true } },
    });
}

describe('my/Destinations', () => {
    it('asks for a phone and a network for a wallet, and never for an account number', () => {
        const page = mountPage();

        expect(page.text()).toContain('Mobile money number');
        expect(page.text()).not.toContain('Account number');
    });

    it('swaps to bank fields when a bank account is chosen', async () => {
        const page = mountPage();

        await page.find('select').setValue('bank_account');

        expect(page.text()).toContain('Account number');
        expect(page.text()).toContain('Bank');
        expect(page.text()).not.toContain('Mobile money number');
    });

    it('tells the member when the name on the account is not theirs', () => {
        const page = mountPage([
            destination({
                resolved_account_name: 'Somebody Else',
                name_matches: false,
                needs_name_confirmation: true,
            }),
        ]);

        expect(page.text()).toContain('In the name of Somebody Else');
        expect(page.text()).toContain(
            'A committee member has to confirm it before money is sent here.',
        );
    });

    it('warns that a freshly changed account needs a second signature', () => {
        const page = mountPage([destination({ is_new: true })]);

        expect(page.text()).toContain('second committee signature');
    });

    it('offers to switch to a destination that is not the default, and not to the one that is', () => {
        const page = mountPage([
            destination({ id: 1, is_default: true }),
            destination({ id: 2, is_default: false, label: 'Absa …4321' }),
        ]);

        const switches = page
            .findAll('button')
            .filter((button) => button.text() === 'Use this one');

        expect(switches).toHaveLength(1);
    });

    it('says nothing is set up rather than showing an empty list', () => {
        expect(mountPage().text()).toContain('Nothing set up yet');
    });
});
