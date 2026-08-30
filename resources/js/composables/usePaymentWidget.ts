import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

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
 * Hands a member over to the provider's hosted payment page, and asks afterwards.
 *
 * Card details are only ever typed into the provider's own widget — nothing here, and
 * nothing on the server, ever sees a card number, which is what keeps the group out of
 * PCI scope. What we mint is the reference, and that is what ties the payment the
 * widget takes back to the intent we wrote down.
 *
 * The browser is never believed about whether the money moved: every way out of the
 * widget — paid, pending, or closed — ends in the same verify call, which asks the
 * provider. A member closing the page without paying is not a failure to hide either:
 * verifying is what releases the draft so they can try again.
 */
export function usePaymentWidget() {
    const page = usePage();

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

    function open(payment: StartedPayment): void {
        const config = widget.value;

        if (!config) {
            return;
        }

        loadScript(config.script).then(() => {
            const lenco = (window as unknown as Record<string, any>).LencoPay;

            if (!lenco) {
                return;
            }

            lenco.getPaid({
                key: config.key,
                reference: payment.reference,
                email: (page.props.auth as any)?.user?.email ?? '',
                amount: payment.amount_ngwee / 100,
                currency: 'ZMW',
                channels: config.channels,
                onSuccess: () => verify(payment.id),
                onConfirmationPending: () => verify(payment.id),
                onClose: () => verify(payment.id),
            });
        });
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

    return { widget, startedPayment, open, openIfStarted, verify };
}
