/**
 * Money helpers. Every amount crossing the wire is an integer of ngwee (K1 = 100),
 * so nothing here ever accepts or returns a floating-point Kwacha value.
 */

export const NGWEE_PER_KWACHA = 100;

/** Renders ngwee as "K1,500.00" — the format used across the portal and exports. */
export function formatMoney(
    ngwee: number,
    options: { decimals?: number; symbol?: boolean } = {},
): string {
    const { decimals = 2, symbol = true } = options;

    const negative = ngwee < 0;
    const formatted = (Math.abs(ngwee) / NGWEE_PER_KWACHA).toLocaleString(
        'en-ZM',
        {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        },
    );

    return `${negative ? '-' : ''}${symbol ? 'K' : ''}${formatted}`;
}

/** Compact form for dashboard tiles, e.g. "K1.5k", "K2.4m". */
export function formatMoneyCompact(ngwee: number): string {
    const kwacha = Math.abs(ngwee) / NGWEE_PER_KWACHA;
    const sign = ngwee < 0 ? '-' : '';

    if (kwacha >= 1_000_000) {
        return `${sign}K${trimZero(kwacha / 1_000_000)}m`;
    }

    if (kwacha >= 1_000) {
        return `${sign}K${trimZero(kwacha / 1_000)}k`;
    }

    return formatMoney(ngwee);
}

/** Parses user input like "K1,500.50" or "1500.5" into ngwee. NaN input yields null. */
export function parseMoney(input: string): number | null {
    const cleaned = input.replace(/[^0-9.-]/g, '');

    if (cleaned === '' || cleaned === '-' || cleaned === '.') {
        return null;
    }

    const kwacha = Number(cleaned);

    if (Number.isNaN(kwacha)) {
        return null;
    }

    return Math.round(kwacha * NGWEE_PER_KWACHA);
}

export function toKwacha(ngwee: number): number {
    return ngwee / NGWEE_PER_KWACHA;
}

export function toNgwee(kwacha: number): number {
    return Math.round(kwacha * NGWEE_PER_KWACHA);
}

/** Rounds ngwee to the nearest valid step, e.g. the K500 savings increment. */
export function roundToStep(ngwee: number, step: number): number {
    if (step <= 0) {
        return ngwee;
    }

    return Math.round(ngwee / step) * step;
}

export function isOnStep(ngwee: number, step: number): boolean {
    return step <= 0 || ngwee % step === 0;
}

function trimZero(value: number): string {
    return value.toFixed(1).replace(/\.0$/, '');
}
