<script setup lang="ts">
/**
 * Enum-driven status pill. Colour comes from the status value itself, so the same
 * status always reads the same colour across every screen in the portal.
 */
import { computed } from 'vue';

import { cn } from '@/lib/utils';

type Tone = 'neutral' | 'success' | 'warning' | 'danger' | 'info' | 'brand';

const props = withDefaults(
    defineProps<{
        /** A backed-enum value such as 'pending' or 'partially_approved'. */
        status: string;
        /** Override the label; defaults to the humanised status. */
        label?: string;
        /** Override the inferred tone. */
        tone?: Tone;
        size?: 'sm' | 'md';
        class?: string;
    }>(),
    { size: 'md' },
);

/** Status values grouped by what they mean, so new enums inherit sane colours. */
const TONES: Record<Tone, string[]> = {
    success: [
        'active',
        'approved',
        'paid',
        'closed_paid',
        'disbursed',
        'settled',
        'complete',
        'completed',
        'posted',
        'successful',
    ],
    warning: [
        'pending',
        'partially_approved',
        'declarations_open',
        'trading',
        'due',
        'late',
        'in_arrears',
        'awaiting-authorization',
    ],
    danger: [
        'rejected',
        'defaulted',
        'overdue',
        'cancelled',
        'suspended',
        'exited',
        'written_off',
        'failed',
        'needs-attention',
    ],
    info: ['draft', 'planned', 'closing', 'review', 'submitted', 'abandoned'],
    brand: ['closed', 'shareout', 'share_out'],
    neutral: [],
};

const classes: Record<Tone, string> = {
    neutral: 'bg-muted text-muted-foreground ring-border',
    success:
        'bg-brand-50 text-brand-800 ring-brand-200 dark:bg-brand-400/15 dark:text-brand-200 dark:ring-brand-400/25',
    warning:
        'bg-gold-50 text-gold-800 ring-gold-200 dark:bg-gold-400/15 dark:text-gold-200 dark:ring-gold-400/25',
    danger: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/25',
    info: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-300 dark:ring-sky-500/25',
    brand: 'bg-brand-900 text-brand-50 ring-brand-900 dark:bg-brand-400/20 dark:text-brand-100 dark:ring-brand-400/30',
};

const resolvedTone = computed<Tone>(() => {
    if (props.tone) {
        return props.tone;
    }

    const value = props.status?.toLowerCase() ?? '';
    const match = (Object.keys(TONES) as Tone[]).find((tone) =>
        TONES[tone].includes(value),
    );

    return match ?? 'neutral';
});

const text = computed(
    () =>
        props.label ??
        props.status
            .replace(/[_-]/g, ' ')
            .replace(/\b\w/g, (c) => c.toUpperCase()),
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex items-center rounded-full font-medium whitespace-nowrap ring-1 ring-inset',
                size === 'sm'
                    ? 'px-2 py-0.5 text-[0.6875rem]'
                    : 'px-2.5 py-1 text-xs',
                classes[resolvedTone],
                $props.class,
            )
        "
    >
        {{ text }}
    </span>
</template>
