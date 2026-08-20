<script setup lang="ts">
/**
 * Tells the user where in the month the group currently is.
 *
 * The whole cycle runs on windows — declarations on the 1st–3rd, trading from the
 * 4th to disbursement — so this state belongs on screen at all times rather than
 * being something a member has to work out from a calendar.
 */
import { CalendarClock, LockKeyhole } from '@lucide/vue';
import { computed } from 'vue';

import { usePermissions } from '@/composables/usePermissions';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

const { currentCycle } = usePermissions();

const month = computed(() => currentCycle.value?.month ?? null);

const message = computed<string | null>(() => {
    const cycle = currentCycle.value;

    if (!cycle || !month.value) {
        return null;
    }

    switch (month.value.window) {
        case 'declarations':
            return `Declarations for ${month.value.label} are open until the 3rd.`;
        case 'before_declarations':
            return `Declarations for ${month.value.label} open on the 1st at 08:00.`;
        case 'between':
            return `Trading for ${month.value.label} starts on the 4th.`;
        case 'trading':
            return `Trading is open. Disbursement is ${new Date(month.value.disbursement_on).toLocaleDateString('en-ZM', { day: 'numeric', month: 'long' })}.`;
        default:
            return `${month.value.label} is closed.`;
    }
});

const tone = computed(() => {
    if (currentCycle.value?.is_lockdown) {
        return 'lockdown';
    }

    return month.value?.window === 'declarations' ||
        month.value?.window === 'trading'
        ? 'open'
        : 'quiet';
});
</script>

<template>
    <div
        v-if="message"
        :class="
            cn(
                'flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-3 py-2 text-xs',
                tone === 'lockdown' &&
                    'border-gold-300 bg-gold-50 text-gold-900 dark:border-gold-400/30 dark:bg-gold-400/10 dark:text-gold-100',
                tone === 'open' &&
                    'border-brand-200 bg-brand-50 text-brand-900 dark:border-brand-400/30 dark:bg-brand-400/10 dark:text-brand-100',
                tone === 'quiet' &&
                    'border-border bg-muted text-muted-foreground',
            )
        "
    >
        <span class="inline-flex items-center gap-1.5 font-medium">
            <CalendarClock class="size-3.5" />
            {{ message }}
        </span>

        <span
            v-if="currentCycle?.is_lockdown"
            class="inline-flex items-center gap-1.5 font-semibold"
        >
            <LockKeyhole class="size-3.5" />
            Lockdown: no new loans, savings capped at
            {{ formatMoney(currentCycle.lockdown_savings_cap_ngwee) }}
        </span>
    </div>
</template>
