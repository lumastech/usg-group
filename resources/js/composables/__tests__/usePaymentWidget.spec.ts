import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();

const props: Record<string, unknown> = {};

vi.mock('@inertiajs/vue3', () => ({
    router: { post: (...args: unknown[]) => post(...args) },
    usePage: () => ({ props }),
}));

import { usePaymentWidget } from '../usePaymentWidget';

const script = 'https://pay.lenco.co/js/v1/inline.js';

const payment = {
    id: 42,
    reference: 'usg-fnd-00042-1',
    amount_ngwee: 25_000,
    channel: 'card',
    status: 'draft',
};

/** The SDK, already loaded, standing in for the provider's own script. */
function stubSdk(): { getPaid: ReturnType<typeof vi.fn> } {
    const sdk = { getPaid: vi.fn() };

    (window as unknown as Record<string, unknown>).LencoPay = sdk;

    const element = document.createElement('script');
    element.src = script;
    document.head.appendChild(element);

    return sdk;
}

/** The nodes the provider's SDK leaves on the page while its widget is open. */
function stubWidgetNodes(): void {
    ['lenco-pay-iframe', 'lenco-pay-bg'].forEach((id) => {
        const node = document.createElement('iframe');
        node.id = id;
        document.body.appendChild(node);
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    post.mockClear();

    document.head.innerHTML = '';
    document.body.innerHTML = '';
    delete (window as unknown as Record<string, unknown>).LencoPay;

    props.payments = { key: 'pub-test', script, channels: ['card'] };
    props.auth = {
        user: {
            name: 'Bertha Phiri',
            email: 'bertha@example.test',
            phone: '0977433571',
        },
    };
});

describe('usePaymentWidget', () => {
    it('opens the provider page with the member already filled in', async () => {
        const sdk = stubSdk();

        usePaymentWidget().open(payment);
        await vi.advanceTimersByTimeAsync(0);

        expect(sdk.getPaid).toHaveBeenCalledTimes(1);

        const options = sdk.getPaid.mock.calls[0][0];

        expect(options.reference).toBe('usg-fnd-00042-1');
        /* Ngwee on the wire, kwacha to the provider. */
        expect(options.amount).toBe(250);
        expect(options.currency).toBe('ZMW');
        expect(options.email).toBe('bertha@example.test');
        expect(options.customer).toEqual({
            firstName: 'Bertha',
            lastName: 'Phiri',
            phone: '0977433571',
        });
    });

    it('gives up on a page that never comes up, and releases the draft', async () => {
        stubSdk();

        const { error, open } = usePaymentWidget();

        open(payment);
        await vi.advanceTimersByTimeAsync(0);

        stubWidgetNodes();

        expect(error.value).toBeNull();

        await vi.advanceTimersByTimeAsync(20_000);

        expect(error.value).toContain('did not load');

        /* The spinner is taken off the screen rather than left over the page. */
        expect(document.getElementById('lenco-pay-iframe')).toBeNull();
        expect(document.getElementById('lenco-pay-bg')).toBeNull();

        /* The SDK holds the open widget in a singleton nothing here can reach, so the
           script goes with it — otherwise every later attempt is silently ignored. */
        expect(document.querySelector(`script[src="${script}"]`)).toBeNull();
        expect(
            (window as unknown as Record<string, unknown>).LencoPay,
        ).toBeUndefined();

        /* Asking the provider is what frees the member to pay on the other rail. */
        expect(post).toHaveBeenCalledWith(
            '/my/payments/42/verify',
            {},
            { preserveScroll: true, preserveState: true },
        );
    });

    it('tells apart a page that never ran from one that refused the payment', async () => {
        stubSdk();

        const { error, open } = usePaymentWidget();

        open(payment);
        await vi.advanceTimersByTimeAsync(0);

        /* Their page ran and asked for the payment — then would not take it. */
        window.dispatchEvent(
            new MessageEvent('message', {
                data: { type: 'lenco:app-loaded' },
                origin: 'https://pay.lenco.co',
            }),
        );

        await vi.advanceTimersByTimeAsync(20_000);

        expect(error.value).toContain('would not start this card payment');
        expect(error.value).not.toContain('did not load');
    });

    it('leaves a widget that did come up alone', async () => {
        stubSdk();

        const { error, open } = usePaymentWidget();

        open(payment);
        await vi.advanceTimersByTimeAsync(0);

        stubWidgetNodes();

        window.dispatchEvent(
            new MessageEvent('message', {
                data: { type: 'lenco:app-initialized' },
                origin: 'https://pay.lenco.co',
            }),
        );

        await vi.advanceTimersByTimeAsync(20_000);

        expect(error.value).toBeNull();
        expect(document.getElementById('lenco-pay-iframe')).not.toBeNull();
        expect(post).not.toHaveBeenCalled();
    });

    it('ignores a message from anywhere but the provider', async () => {
        stubSdk();

        const { error, open } = usePaymentWidget();

        open(payment);
        await vi.advanceTimersByTimeAsync(0);

        window.dispatchEvent(
            new MessageEvent('message', {
                data: { type: 'lenco:app-initialized' },
                origin: 'https://not-the-provider.test',
            }),
        );

        await vi.advanceTimersByTimeAsync(20_000);

        expect(error.value).toContain('did not load');
    });

    it('says so when the provider script cannot be reached at all', async () => {
        const { error, open } = usePaymentWidget();

        open(payment);

        /* No script tag was stubbed, so the load is attempted and fails. */
        const element = document.querySelector(
            `script[src="${script}"]`,
        ) as HTMLScriptElement;

        element.onerror?.(new Event('error'));

        await vi.advanceTimersByTimeAsync(0);

        expect(error.value).toContain('could not reach');
        expect(post).toHaveBeenCalledTimes(1);
    });
});
