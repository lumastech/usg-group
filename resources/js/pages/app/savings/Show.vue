<script setup lang="ts">
/**
 * One member's savings, and every entry that produced them.
 *
 * The chart is drawn as plain SVG from the server's monthly figures — bars for what
 * was saved each month, a line for the running total — so the drill-down carries no
 * charting dependency and prints as it renders.
 */
import { Link } from '@inertiajs/vue3';
import { Coins, Download, PiggyBank, Wallet } from '@lucide/vue';
import { computed } from 'vue';

import {
    AppButton,
    AppCard,
    DataTable,
    StatCard,
    StatusBadge,
} from '@/components/unity';
import type { Column, PaginationMeta } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { Member } from '@/types/members';
import type { SavingsMonth, SavingsTransaction } from '@/types/savings';

const props = defineProps<{
    member: Member;
    history: SavingsMonth[];
    transactions: { data: SavingsTransaction[]; meta: PaginationMeta };
    abilities: { record: boolean };
}>();

const totals = computed(() => {
    const last = props.history[props.history.length - 1];

    return {
        savings: last?.cumulative_savings_ngwee ?? 0,
        interest: last?.cumulative_interest_ngwee ?? 0,
    };
});

/** Bar heights and the cumulative line share one scale so the two read together. */
const chart = computed(() => {
    const width = 720;
    const height = 200;
    const peak = Math.max(
        1,
        ...props.history.map((month) => month.cumulative_savings_ngwee),
    );
    const step = width / Math.max(1, props.history.length);

    const bars = props.history.map((month, index) => {
        const barHeight = (month.savings_ngwee / peak) * height;

        return {
            key: month.month_id,
            label: month.label,
            lockdown: month.lockdown,
            x: index * step + step * 0.2,
            y: height - barHeight,
            width: step * 0.6,
            height: barHeight,
            savings: month.savings_ngwee,
            cumulative: month.cumulative_savings_ngwee,
        };
    });

    const line = props.history
        .map((month, index) => {
            const x = index * step + step / 2;
            const y = height - (month.cumulative_savings_ngwee / peak) * height;

            return `${x},${y}`;
        })
        .join(' ');

    return { width, height, bars, line };
});

const columns: Column<SavingsTransaction>[] = [
    { key: 'occurred_on', label: 'Date' },
    { key: 'month_label', label: 'Month', hideOnMobile: true },
    { key: 'type', label: 'Type' },
    { key: 'amount_ngwee', label: 'Amount', numeric: true },
    {
        key: 'declared_amount_ngwee',
        label: 'Declared',
        numeric: true,
        hideOnMobile: true,
    },
    { key: 'recorded_by', label: 'Recorded by', hideOnMobile: true },
];
</script>

<template>
    <AdminLayout
        title="Member savings"
        :heading="member.full_name"
        :description="`Member ${member.member_number} · savings history`"
    >
        <template #actions>
            <a :href="`/app/savings/${member.id}/statement`">
                <AppButton variant="outline" size="sm">
                    <template #icon><Download class="size-4" /></template>
                    Statement
                </AppButton>
            </a>
            <Link :href="`/app/members/${member.id}`">
                <AppButton variant="ghost" size="sm">Profile</AppButton>
            </Link>
        </template>

        <div class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatCard
                    label="Total savings"
                    :ngwee="totals.savings"
                    :icon="PiggyBank"
                    accent="brand"
                />
                <StatCard
                    label="Interest earned"
                    :ngwee="totals.interest"
                    :icon="Coins"
                />
                <StatCard
                    label="Net value"
                    :ngwee="totals.savings + totals.interest"
                    :icon="Wallet"
                    hint="Less anything still owed"
                />
            </div>

            <AppCard
                title="Month by month"
                description="Bars are the month's savings; the line is the running total."
            >
                <div class="scrollbar-thin overflow-x-auto">
                    <svg
                        :viewBox="`0 0 ${chart.width} ${chart.height + 24}`"
                        class="h-56 w-full min-w-[36rem]"
                        role="img"
                        aria-label="Monthly savings and cumulative total"
                    >
                        <rect
                            v-for="bar in chart.bars"
                            :key="bar.key"
                            :x="bar.x"
                            :y="bar.y"
                            :width="bar.width"
                            :height="bar.height"
                            rx="3"
                            class="fill-brand-500/70"
                        >
                            <title>
                                {{ bar.label }}:
                                {{ formatMoney(bar.savings) }} (running
                                {{ formatMoney(bar.cumulative) }})
                            </title>
                        </rect>

                        <polyline
                            :points="chart.line"
                            fill="none"
                            stroke-width="2"
                            class="stroke-gold-500"
                        />

                        <text
                            v-for="(bar, index) in chart.bars"
                            :key="`label-${bar.key}`"
                            :x="bar.x + bar.width / 2"
                            :y="chart.height + 16"
                            text-anchor="middle"
                            class="fill-muted-foreground text-[9px]"
                        >
                            {{ index % 2 === 0 ? bar.label : '' }}
                        </text>
                    </svg>
                </div>
            </AppCard>

            <AppCard
                title="Ledger"
                description="Append-only: corrections appear as their own reversing entries."
                flush
            >
                <DataTable
                    :rows="transactions.data"
                    :columns="columns"
                    :meta="transactions.meta"
                    :row-key="(row) => row.id"
                    empty-title="Nothing recorded yet"
                    empty-description="This member has no savings entries in the current cycle."
                >
                    <template #cell-type="{ row }">
                        <StatusBadge :status="row.type" size="sm" />
                    </template>
                    <template #cell-amount_ngwee="{ row }">
                        <span
                            :class="
                                row.amount_ngwee < 0 ? 'text-destructive' : ''
                            "
                        >
                            {{ formatMoney(row.amount_ngwee) }}
                        </span>
                    </template>
                    <template #cell-declared_amount_ngwee="{ row }">
                        {{
                            row.declared_amount_ngwee === null
                                ? '—'
                                : formatMoney(row.declared_amount_ngwee)
                        }}
                    </template>
                    <template #cell-recorded_by="{ row }">
                        {{ row.recorded_by ?? '—' }}
                    </template>
                </DataTable>
            </AppCard>
        </div>
    </AdminLayout>
</template>
