<script setup lang="ts">
/**
 * A headline figure for the dashboard: label, value, optional trend and icon.
 *
 * Pass `ngwee` for money (formatted and tabular) or `value` for anything else.
 */
import { TrendingDown, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';

import type { Component } from 'vue';
import { cn } from '@/lib/utils';
import MoneyText from './MoneyText.vue';

const props = withDefaults(
    defineProps<{
        label: string;
        /** Money in ngwee. Takes precedence over `value`. */
        ngwee?: number;
        value?: string | number;
        hint?: string;
        /** Percentage change; positive renders green, negative red. */
        trend?: number;
        trendLabel?: string;
        icon?: Component;
        /** Gold accent marks the single most important tile on a screen. */
        accent?: 'brand' | 'gold' | 'none';
        compact?: boolean;
        class?: string;
    }>(),
    { accent: 'none' },
);

const hasTrend = computed(
    () => typeof props.trend === 'number' && Number.isFinite(props.trend),
);
const trendUp = computed(() => (props.trend ?? 0) >= 0);

const accentRing = computed(() =>
    props.accent === 'gold'
        ? 'ring-1 ring-gold-300/70 dark:ring-gold-400/30'
        : props.accent === 'brand'
          ? 'ring-1 ring-brand-200 dark:ring-brand-400/30'
          : '',
);

const iconTone = computed(() =>
    props.accent === 'gold'
        ? 'bg-gold-100 text-gold-700 dark:bg-gold-400/15 dark:text-gold-300'
        : 'bg-brand-50 text-brand-700 dark:bg-brand-400/15 dark:text-brand-300',
);
</script>

<template>
    <div
        :class="
            cn(
                'rounded-xl border border-border bg-card p-5 shadow-card',
                accentRing,
                $props.class,
            )
        "
    >
        <div class="flex items-start justify-between gap-3">
            <p
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                {{ label }}
            </p>
            <span
                v-if="icon"
                :class="
                    cn(
                        'grid size-8 shrink-0 place-items-center rounded-lg',
                        iconTone,
                    )
                "
            >
                <component :is="icon" class="size-4" aria-hidden="true" />
            </span>
        </div>

        <p
            class="mt-3 text-2xl font-semibold tracking-tight text-card-foreground"
        >
            <MoneyText
                v-if="ngwee !== undefined"
                :ngwee="ngwee"
                :compact="compact"
            />
            <span v-else class="tabular">{{ value ?? '—' }}</span>
        </p>

        <div
            v-if="hasTrend || hint"
            class="mt-2 flex items-center gap-2 text-xs"
        >
            <span
                v-if="hasTrend"
                :class="
                    cn(
                        'tabular inline-flex items-center gap-1 font-medium',
                        trendUp
                            ? 'text-brand-600 dark:text-brand-400'
                            : 'text-destructive',
                    )
                "
            >
                <component
                    :is="trendUp ? TrendingUp : TrendingDown"
                    class="size-3.5"
                    aria-hidden="true"
                />
                {{ trendUp ? '+' : '' }}{{ trend }}%
            </span>
            <span class="text-muted-foreground">{{ trendLabel ?? hint }}</span>
        </div>
    </div>
</template>
