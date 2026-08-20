<script setup lang="ts">
/**
 * The month's window, counted down live.
 *
 * The server sends the seconds remaining rather than a target timestamp so the
 * countdown is anchored to the server's clock, not the phone's — a member whose
 * handset is a day out still sees the right answer.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

import { cn } from '@/lib/utils';
import type { CycleWindow } from '@/types/auth';

const props = withDefaults(
    defineProps<{
        window: CycleWindow;
        secondsRemaining: number | null;
        label?: string;
        class?: string;
    }>(),
    {},
);

const elapsed = ref(0);
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    timer = setInterval(() => {
        elapsed.value += 1;
    }, 1000);
});

onBeforeUnmount(() => clearInterval(timer));

const remaining = computed<number | null>(() =>
    props.secondsRemaining === null
        ? null
        : Math.max(0, props.secondsRemaining - elapsed.value),
);

/** Days and hours while there is a while to go; minutes and seconds near the wire. */
const countdown = computed<string | null>(() => {
    const total = remaining.value;

    if (total === null) {
        return null;
    }

    const days = Math.floor(total / 86_400);
    const hours = Math.floor((total % 86_400) / 3_600);
    const minutes = Math.floor((total % 3_600) / 60);
    const seconds = total % 60;

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m ${seconds}s`;
});

const COPY: Record<CycleWindow, { title: string; suffix: string }> = {
    before_declarations: {
        title: 'Declarations have not opened',
        suffix: 'until they open',
    },
    declarations: { title: 'Declarations are open', suffix: 'left to declare' },
    between: { title: 'Declarations are closed', suffix: 'until trading' },
    trading: { title: 'Trading is open', suffix: 'until it concludes' },
    closed: { title: 'This month is closed', suffix: '' },
};

const tone = computed(() =>
    props.window === 'declarations'
        ? 'border-brand-200 bg-brand-50 text-brand-900 dark:border-brand-400/30 dark:bg-brand-400/10 dark:text-brand-100'
        : props.window === 'trading'
          ? 'border-gold-300 bg-gold-50 text-gold-900 dark:border-gold-400/30 dark:bg-gold-400/10 dark:text-gold-100'
          : 'border-border bg-muted text-muted-foreground',
);
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-wrap items-center justify-between gap-3 rounded-xl border px-4 py-3',
                tone,
                $props.class,
            )
        "
    >
        <p class="text-sm font-semibold">
            {{ label ?? COPY[window].title }}
        </p>

        <p v-if="countdown" class="tabular text-sm font-medium">
            {{ countdown }}
            <span class="font-normal opacity-80">{{
                COPY[window].suffix
            }}</span>
        </p>
    </div>
</template>
