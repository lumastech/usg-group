import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, ref, type Ref } from 'vue';

import type { PaymentIntent } from '@/types/payments';
import { usePaymentWatch } from '../usePaymentWatch';

const START = new Date('2026-01-05T09:00:00Z').getTime();

function payment(overrides: Partial<PaymentIntent> = {}): PaymentIntent {
    return {
        id: 7,
        status: 'awaiting-authorization',
        has_stalled: false,
        initiated_at: new Date(START).toISOString(),
        created_at: new Date(START).toISOString(),
        ...overrides,
    } as PaymentIntent;
}

/**
 * The composable needs a component to live in: it holds a timer and a listener that
 * both have to be given up when the screen goes away.
 */
function watching(
    payments: Ref<PaymentIntent[]>,
    check: (p: PaymentIntent) => void,
) {
    return mount(
        defineComponent({
            setup() {
                usePaymentWatch(() => payments.value, check);

                return () => null;
            },
        }),
    );
}

/** Moves both clocks: the timers fire, and the payment's age advances with them. */
function advance(seconds: number): void {
    vi.advanceTimersByTime(seconds * 1_000);
}

describe('usePaymentWatch', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(START);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('leaves the member alone for the first twenty seconds', () => {
        const check = vi.fn();
        watching(ref([payment()]), check);

        advance(19);

        expect(check).not.toHaveBeenCalled();

        advance(2);

        expect(check).toHaveBeenCalledTimes(1);
    });

    it('then asks every ten seconds, and gives up after eighty', () => {
        const check = vi.fn();
        watching(ref([payment()]), check);

        advance(21);
        expect(check).toHaveBeenCalledTimes(1);

        advance(10);
        expect(check).toHaveBeenCalledTimes(2);

        advance(10);
        expect(check).toHaveBeenCalledTimes(3);

        /* Past the window the member has put the phone down; the screen's own button
           is a better answer than a page that asks the provider forever. */
        advance(120);
        expect(check).toHaveBeenCalledTimes(7);

        advance(120);
        expect(check).toHaveBeenCalledTimes(7);
    });

    it('does not chase a payment that has come to rest', () => {
        const check = vi.fn();
        watching(ref([payment({ status: 'posted' })]), check);

        advance(60);

        expect(check).not.toHaveBeenCalled();
    });

    /* Nothing has been sent for a draft, so the provider has never heard of the
       reference — and a check would read that as a card the member opened and closed,
       releasing the draft they are at that moment typing their card into. */
    it('leaves a card the member is still inside the widget for alone', () => {
        const check = vi.fn();
        watching(ref([payment({ status: 'draft' })]), check);

        advance(60);

        expect(check).not.toHaveBeenCalled();
    });

    it('does not chase a prompt nobody approved', () => {
        const check = vi.fn();
        watching(ref([payment({ has_stalled: true })]), check);

        advance(60);

        expect(check).not.toHaveBeenCalled();
    });

    it('stops asking once the payment lands', () => {
        const check = vi.fn();
        const payments = ref([payment()]);

        watching(payments, check);

        advance(21);
        expect(check).toHaveBeenCalledTimes(1);

        payments.value = [payment({ status: 'posted' })];

        advance(60);
        expect(check).toHaveBeenCalledTimes(1);
    });

    it('gives up its clock when the screen goes away', () => {
        const check = vi.fn();
        const page = watching(ref([payment()]), check);

        page.unmount();

        advance(60);

        expect(check).not.toHaveBeenCalled();
    });
});
