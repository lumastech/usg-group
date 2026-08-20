<script setup lang="ts">
/**
 * A member's own savings: what they have put in, what it has earned, and where they
 * stand. Built for a phone — tiles first, then the month list, then the ledger.
 */
import { Coins, Download, PiggyBank, Wallet } from '@lucide/vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    StatCard,
    StatusBadge,
} from '@/components/unity';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    SavingsMonth,
    SavingsTotals,
    SavingsTransaction,
} from '@/types/savings';

defineProps<{
    member: { id: number; full_name: string; member_number: number } | null;
    history: SavingsMonth[];
    totals: SavingsTotals | null;
    transactions: SavingsTransaction[];
    cycleName: string | null;
}>();
</script>

<template>
    <MemberLayout
        title="My savings"
        heading="My savings"
        :description="cycleName ?? undefined"
    >
        <AppCard v-if="!member || !totals">
            <EmptyState
                title="No member record"
                description="Your login is not linked to a member in the current cycle. Ask the treasurer to link it."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="My savings"
                    :ngwee="totals.savings_ngwee"
                    :icon="PiggyBank"
                    accent="brand"
                />
                <StatCard
                    label="Interest earned"
                    :ngwee="totals.interest_ngwee"
                    :icon="Coins"
                    accent="gold"
                />
                <StatCard
                    label="Net value"
                    :ngwee="totals.net_value_ngwee"
                    :icon="Wallet"
                    hint="Savings and interest, less what I owe"
                />
            </div>

            <a href="/my/savings/statement" class="block">
                <AppButton variant="outline" block>
                    <template #icon><Download class="size-4" /></template>
                    Download my statement (PDF)
                </AppButton>
            </a>

            <AppCard title="Month by month" flush>
                <ul class="divide-y divide-border">
                    <li
                        v-for="month in history"
                        :key="month.month_id"
                        class="flex items-center justify-between gap-3 px-5 py-3"
                    >
                        <span class="min-w-0">
                            <span
                                class="block text-sm font-medium text-card-foreground"
                            >
                                {{ month.full_label }}
                            </span>
                            <span class="block text-xs text-muted-foreground">
                                Running total
                                {{
                                    formatMoney(month.cumulative_savings_ngwee)
                                }}
                                <template v-if="month.lockdown">
                                    · lockdown</template
                                >
                            </span>
                        </span>
                        <span class="shrink-0 text-right">
                            <span
                                class="tabular block text-sm font-semibold"
                                :class="
                                    month.savings_ngwee === 0
                                        ? 'text-muted-foreground'
                                        : 'text-card-foreground'
                                "
                            >
                                {{ formatMoney(month.savings_ngwee) }}
                            </span>
                            <span
                                v-if="month.interest_ngwee !== 0"
                                class="tabular block text-xs text-gold-700 dark:text-gold-300"
                            >
                                +{{
                                    formatMoney(month.interest_ngwee)
                                }}
                                interest
                            </span>
                        </span>
                    </li>
                </ul>
            </AppCard>

            <AppCard
                title="My entries"
                description="Every line the treasurer recorded. Corrections appear as their own reversing entry."
                flush
            >
                <EmptyState
                    v-if="transactions.length === 0"
                    title="Nothing recorded yet"
                    description="Your savings will appear here once the treasurer records them."
                />

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="transaction in transactions"
                        :key="transaction.id"
                        class="flex items-center justify-between gap-3 px-5 py-3"
                    >
                        <span class="min-w-0">
                            <StatusBadge :status="transaction.type" size="sm" />
                            <span
                                class="mt-1 block text-xs text-muted-foreground"
                            >
                                {{
                                    transaction.month_label ??
                                    transaction.occurred_on
                                }}
                            </span>
                        </span>
                        <span
                            class="tabular shrink-0 text-sm font-semibold"
                            :class="
                                transaction.amount_ngwee < 0
                                    ? 'text-destructive'
                                    : 'text-card-foreground'
                            "
                        >
                            {{ formatMoney(transaction.amount_ngwee) }}
                        </span>
                    </li>
                </ul>
            </AppCard>
        </div>
    </MemberLayout>
</template>
