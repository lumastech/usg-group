<script setup lang="ts">
/**
 * A paired bar chart drawn in CSS, for money in against money out per month.
 *
 * Deliberately not a charting library: the portal ships to phones on Zambian mobile
 * data, and two divs a month cost nothing. Values are ngwee and are only ever
 * formatted, never computed on, here.
 */
import { computed } from 'vue';

import { formatMoney, formatMoneyCompact } from '@/lib/money';
import { cn } from '@/lib/utils';

export type BarChartPoint = {
    label: string;
    /** The upward bar, in ngwee. */
    primary: number;
    /** The downward bar, in ngwee. */
    secondary: number;
};

const props = withDefaults(
    defineProps<{
        points: BarChartPoint[];
        primaryLabel?: string;
        secondaryLabel?: string;
        class?: string;
    }>(),
    { primaryLabel: 'In', secondaryLabel: 'Out' },
);

/** One scale for both series, so a K900 outflow reads taller than a K300 inflow. */
const peak = computed(() =>
    Math.max(
        1,
        ...props.points.map((point) =>
            Math.max(point.primary, point.secondary),
        ),
    ),
);

function height(value: number): string {
    return `${Math.max(value > 0 ? 3 : 0, (value / peak.value) * 100)}%`;
}
</script>

<template>
    <div :class="cn('space-y-3', props.class)">
        <div class="flex items-center gap-4 text-xs text-muted-foreground">
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm bg-brand-600" />
                {{ primaryLabel }}
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="size-2.5 rounded-sm bg-gold-400" />
                {{ secondaryLabel }}
            </span>
            <span class="tabular ml-auto"
                >Peak {{ formatMoneyCompact(peak) }}</span
            >
        </div>

        <div class="flex h-40 items-end gap-1.5 overflow-x-auto">
            <div
                v-for="point in points"
                :key="point.label"
                class="flex min-w-9 flex-1 flex-col items-center gap-1.5"
            >
                <div
                    class="flex h-full w-full items-end justify-center gap-0.5"
                >
                    <div
                        class="w-1/2 rounded-t bg-brand-600 transition-[height]"
                        :style="{ height: height(point.primary) }"
                        :title="`${point.label} in: ${formatMoney(point.primary)}`"
                    />
                    <div
                        class="w-1/2 rounded-t bg-gold-400 transition-[height]"
                        :style="{ height: height(point.secondary) }"
                        :title="`${point.label} out: ${formatMoney(point.secondary)}`"
                    />
                </div>

                <span class="text-[10px] text-muted-foreground">
                    {{ point.label }}
                </span>
            </div>
        </div>
    </div>
</template>
