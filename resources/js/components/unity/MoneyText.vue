<script setup lang="ts">
/**
 * Renders an ngwee integer as Kwacha. Always tabular so columns of money align.
 *
 * Negative amounts read in the destructive colour when `signed` is set — used on
 * the interest statement, where a heavy borrower shows a net loss.
 */
import { computed } from 'vue';

import { formatMoney, formatMoneyCompact } from '@/lib/money';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        ngwee: number;
        compact?: boolean;
        /** Colour negatives red and prefix positives with +. */
        signed?: boolean;
        decimals?: number;
        class?: string;
    }>(),
    { decimals: 2 },
);

const text = computed(() => {
    const base = props.compact
        ? formatMoneyCompact(props.ngwee)
        : formatMoney(props.ngwee, { decimals: props.decimals });

    return props.signed && props.ngwee > 0 ? `+${base}` : base;
});
</script>

<template>
    <span
        :class="
            cn(
                'tabular',
                signed && ngwee < 0 && 'text-destructive',
                signed && ngwee > 0 && 'text-brand-600 dark:text-brand-400',
                $props.class,
            )
        "
        >{{ text }}</span
    >
</template>
