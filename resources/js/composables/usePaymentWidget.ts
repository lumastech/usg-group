import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import type { PaymentWidgetConfig } from '@/types/payments';

/** The handover a card payment leaves in the flash bag when it is drafted. */
export type StartedPayment = {
    id: number;
    reference: string;
    amount_ngwee: number;
    channel: string;
    status: string;
};

/**
 * How long the provider's page gets to come up before we call it stuck.
 *
 * The widget shows its own spinner over a hidden iframe and only reveals the payment
 * form once its page reports itself initialised. If that report never arrives — a key
 * the provider will not accept from this domain, a page that failed to load — the
 * spinner is all the member ever sees, and nothing in the SDK ever gives up on it.
 */
const INITIALISE_TIMEOUT_MS = 20_000;

/** The ids the provider's SDK gives the nodes it appends to the body. */
const WIDGET_NODE_IDS = ['lenco-pay-iframe', 'lenco-pay-bg'];

/** The SDK's message that its page is up and the form is on screen. */
const INITIALISED_MESSAGE = 'lenco:app-initialized';

/**
 * Hands a member over to the provider's hosted payment page, and asks afterwards.
 *
 * Card details are only ever typed into the provider's own widget — nothing here, and
 * nothing on the server, ever sees a card number, which is what keeps the group out of
 * PCI scope. What we mint is the reference, and that is what ties the payment the
 * widget takes back to the intent we wrote down.
 *
 * The browser is never believed about whether the money moved: every way out of the
 * widget — paid, pending, closed, or never opened at all — ends in the same verify
 * call, which asks the provider. A member closing the page without paying is not a
 * failure to hide either: verifying is what releases the draft so they can try again.
 */
export function usePaymentWidget() {
    const page = usePage();

    /**
     * Why the provider's page did not come up, for the screen to show.
     *
     * Whatever went wrong happened inside somebody else's iframe, so there is nothing
     * here to diagnose it with — what matters is that the member is told, and pointed
     * at the rail that does not depend on it.
     */
    const error = ref<string | null>(null);

    const widget = computed<PaymentWidgetConfig | null>(
        () => (page.props.payments as PaymentWidgetConfig | null) ?? null,
    );

    /** The card payment just drafted, if the last request drafted one. */
    function startedPayment(): StartedPayment | null {
        const flash = page.props.flash as Record<string, unknown> | undefined;

        return (flash?.startedPayment as StartedPayment | undefined) ?? null;
    }

    /**
     * Loaded on demand rather than on every visit: most visits to a payment screen are
     * to look at something already paid, and a member on a village connection should
     * not pay for a script they are not going to use.
     */
    function loadScript(src: string): Promise<void> {
        if (document.querySelector(`script[src="${src}"]`)) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const element = document.createElement('script');
            element.src = src;
            element.onload = () => resolve();
            element.onerror = () => reject(new Error('script failed'));
            document.head.appendChild(element);
        });
    }

    function verify(id: number): void {
        router.post(`/my/payments/${id}/verify`, {}, { preserveScroll: true });
    }

    /**
     * Takes the stuck widget off the screen and leaves the SDK able to open another.
     *
     * The SDK holds the open widget in a module-level singleton it clears only when the
     * provider's own page says it closed — a page that never came up never says so, and
     * nothing here can reach that singleton. So the script goes too, and the next
     * attempt loads a fresh copy of it; without that, every later click is silently
     * ignored and the member is left with a button that does nothing.
     */
    function teardown(config: PaymentWidgetConfig): void {
        WIDGET_NODE_IDS.forEach((id) => document.getElementById(id)?.remove());

        document
            .querySelectorAll(`script[src="${config.script}"]`)
            .forEach((element) => element.remove());

        delete (window as unknown as Record<string, unknown>).LencoPay;
    }

    /**
     * Gives up on a widget whose page never came up.
     *
     * Verifying is what makes this safe to do: the draft is released by asking the
     * provider, so the member is free to pay on the other rail instead of being held
     * by an attempt that never started.
     */
    function abandon(
        config: PaymentWidgetConfig,
        payment: StartedPayment,
        reason: string,
    ): void {
        teardown(config);

        error.value = reason;

        verify(payment.id);
    }

    /**
     * Watches for the provider's page reporting itself up, and gives up if it does not.
     *
     * Only messages from the provider's own origin are read, and only to learn that the
     * form is on screen — nothing the iframe says is ever treated as proof a payment
     * happened.
     */
    function watchForInitialisation(
        config: PaymentWidgetConfig,
        payment: StartedPayment,
    ): void {
        const origin = new URL(config.script).origin;

        const timer = window.setTimeout(() => {
            window.removeEventListener('message', onMessage);

            abandon(
                config,
                payment,
                'The card payment page did not load. Nothing has been taken — approve a ' +
                    'prompt on your phone instead, or try the card again in a moment.',
            );
        }, INITIALISE_TIMEOUT_MS);

        function onMessage(event: MessageEvent): void {
            if (event.origin !== origin) {
                return;
            }

            const type = (event.data as { type?: string } | null)?.type;

            if (type === INITIALISED_MESSAGE) {
                window.clearTimeout(timer);
                window.removeEventListener('message', onMessage);
            }
        }

        window.addEventListener('message', onMessage, false);
    }

    /** The member's own details, so the provider's form opens filled in. */
    function customer(): Record<string, string> | undefined {
        const user = (page.props.auth as any)?.user;

        if (!user) {
            return undefined;
        }

        const [firstName, ...rest] = String(user.name ?? '').split(' ');

        const details: Record<string, string> = {};

        if (firstName) {
            details.firstName = firstName;
        }

        if (rest.length > 0) {
            details.lastName = rest.join(' ');
        }

        if (user.phone) {
            details.phone = String(user.phone);
        }

        return Object.keys(details).length > 0 ? details : undefined;
    }

    function open(payment: StartedPayment): void {
        const config = widget.value;

        if (!config) {
            return;
        }

        error.value = null;

        loadScript(config.script)
            .then(() => {
                const lenco = (window as unknown as Record<string, any>)
                    .LencoPay;

                if (!lenco) {
                    abandon(
                        config,
                        payment,
                        'The card payment page could not be started. Nothing has been taken — ' +
                            'approve a prompt on your phone instead.',
                    );

                    return;
                }

                watchForInitialisation(config, payment);

                lenco.getPaid({
                    key: config.key,
                    reference: payment.reference,
                    email: (page.props.auth as any)?.user?.email ?? '',
                    amount: payment.amount_ngwee / 100,
                    currency: 'ZMW',
                    channels: config.channels,
                    customer: customer(),
                    onSuccess: () => verify(payment.id),
                    onConfirmationPending: () => verify(payment.id),
                    onClose: () => verify(payment.id),
                });
            })
            .catch(() =>
                abandon(
                    config,
                    payment,
                    'We could not reach the payment provider. Nothing has been taken — ' +
                        'approve a prompt on your phone instead.',
                ),
            );
    }

    /** Opens the widget if the request that just finished drafted a card payment. */
    function openIfStarted(): boolean {
        const payment = startedPayment();

        if (payment === null || payment.channel !== 'card') {
            return false;
        }

        open(payment);

        return true;
    }

    return { widget, error, startedPayment, open, openIfStarted, verify };
}
