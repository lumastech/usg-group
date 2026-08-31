import { onBeforeUnmount, onMounted, watch } from 'vue';

import type { PaymentIntent } from '@/types/payments';

/**
 * Watches a prompt the member is approving, and asks the provider on their behalf.
 *
 * A mobile money prompt is answered on the handset, so nothing reaches this screen when
 * it is approved: the credit arrives by webhook or by the poller's own clock, both of
 * which are slower than the member standing in front of the page. Without this they
 * watch an unchanged balance and conclude the payment failed — which is how somebody
 * pays twice.
 *
 * The schedule is derived from the payment's own timestamp rather than counted here, so
 * it survives the page re-rendering after every check: whatever the component does, a
 * payment initiated ninety seconds ago is past being asked about.
 */

/** Nobody approves a prompt faster than this, so the first twenty seconds are quiet. */
const FIRST_CHECK_MS = 20_000;

/** Then every ten seconds — about as often as the provider's answer can change. */
const INTERVAL_MS = 10_000;

/**
 * And nothing after eighty.
 *
 * Past that the member has put the phone down, and the screen's own button is a better
 * answer than a page that asks the provider forever.
 */
const GIVE_UP_MS = 80_000;

/** How often the schedule is re-examined. Cheap: most ticks decide to do nothing. */
const TICK_MS = 1_000;

/**
 * Statuses worth asking about. Anything else has come to rest.
 *
 * A draft is deliberately not one of them. Nothing has been sent for it, so the
 * provider has never heard of the reference and a check would read that as a card the
 * member opened and closed — releasing the very draft they are typing their card into
 * inside the widget. The widget's own callbacks verify that rail; this one watches the
 * prompt on the handset.
 */
const WATCHABLE = [
    'pending',
    'awaiting-authorization',
    'successful',
    'settled',
];

/** When the prompt went out, in milliseconds, or null if we cannot tell. */
function startedAt(payment: PaymentIntent): number | null {
    const stamp = payment.initiated_at ?? payment.created_at;

    if (stamp === null) {
        return null;
    }

    const parsed = Date.parse(stamp);

    return Number.isNaN(parsed) ? null : parsed;
}

/**
 * Whether this payment is still worth an automatic check.
 *
 * A stalled prompt is not: the server has already stopped treating it as live, and the
 * screen is offering the member another attempt instead.
 */
function watchable(payment: PaymentIntent): boolean {
    return WATCHABLE.includes(payment.status) && !payment.has_stalled;
}

export function usePaymentWatch(
    payments: () => PaymentIntent[],
    check: (payment: PaymentIntent) => void,
) {
    /** When each payment was last asked about, so a re-render cannot re-ask at once. */
    const asked = new Map<number, number>();

    let timer: number | null = null;

    /** The payment due to be asked about now, if any is. */
    function due(): PaymentIntent | null {
        const now = Date.now();

        return (
            payments()
                .filter(watchable)
                .find((payment) => {
                    const started = startedAt(payment);

                    if (started === null) {
                        return false;
                    }

                    const elapsed = now - started;

                    if (elapsed < FIRST_CHECK_MS || elapsed > GIVE_UP_MS) {
                        return false;
                    }

                    const last = asked.get(payment.id);

                    return last === undefined || now - last >= INTERVAL_MS;
                }) ?? null
        );
    }

    function tick(): void {
        const payment = due();

        if (payment === null) {
            return;
        }

        asked.set(payment.id, Date.now());

        check(payment);
    }

    /**
     * Coming back to the tab is the strongest signal there is.
     *
     * The member left this page to approve the prompt in their money app; the moment
     * they return is the moment the answer exists, and a mobile browser has been
     * throttling our timers the whole time they were away.
     */
    function onVisible(): void {
        if (document.visibilityState === 'visible') {
            tick();
        }
    }

    function start(): void {
        if (timer !== null) {
            return;
        }

        timer = window.setInterval(tick, TICK_MS);
    }

    function stop(): void {
        if (timer !== null) {
            window.clearInterval(timer);
            timer = null;
        }
    }

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisible);
    });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisible);
        stop();
    });

    /* Nothing in flight, no timer: a screen a member is only reading their history on
       should not be running a clock. */
    watch(
        () => payments().filter(watchable).length,
        (waiting) => (waiting > 0 ? start() : stop()),
        { immediate: true },
    );

    return { stop };
}
