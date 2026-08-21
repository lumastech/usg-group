<script setup lang="ts">
/**
 * Members whose loans have outrun their savings.
 *
 * The workbook's "Min Repayments-Negative NV" sheet. For each member under water it
 * shows what they owe today and what they must pay in each of the next three months
 * to be level again — interest first, repayment after, which is the order the trading
 * day itself follows.
 */
import { Link } from '@inertiajs/vue3';
import { Calendar, TrendingDown, UserMinus } from '@lucide/vue';

import {
    AppCard,
    EmptyState,
    MoneyText,
    StatCard,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { RiskProjection } from '@/types/shareout';

defineProps<{
    cycle: { id: number; name: string; monthly_interest_bps: number } | null;
    projection: RiskProjection | null;
}>();
</script>

<template>
    <AdminLayout
        title="Risk"
        heading="Members under water"
        description="Negative net value, and the minimum repayments that bring it back to zero."
    >
        <AppCard v-if="!cycle || !projection">
            <EmptyState
                title="No active cycle"
                description="Activate a cycle to see who is under water."
            />
        </AppCard>

        <AppCard v-else-if="projection.rows.length === 0">
            <EmptyState
                title="Nobody is under water"
                description="Every member's savings and interest cover what they still owe."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Members under water"
                    :value="projection.totals.members"
                    :icon="UserMinus"
                    accent="gold"
                />
                <StatCard
                    label="Total shortfall"
                    :ngwee="projection.totals.shortfall_ngwee"
                    :icon="TrendingDown"
                    hint="What the group is owed beyond their savings"
                />
                <StatCard
                    label="Minimum first month"
                    :ngwee="projection.totals.minimum_monthly_ngwee"
                    :icon="Calendar"
                    :hint="`Over ${projection.horizon_months} months at ${projection.monthly_rate_bps / 100}% a month`"
                />
            </div>

            <AppCard
                v-for="row in projection.rows"
                :key="row.member_id"
                flush
            >
                <div class="space-y-3 p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <Link
                                :href="row.href"
                                class="font-medium hover:underline"
                            >
                                {{ row.full_name }}
                            </Link>
                            <span class="ms-2 text-xs text-muted-foreground">
                                #{{ row.member_number }} ·
                                {{ row.status_label }}
                            </span>
                        </div>
                        <div class="text-sm text-muted-foreground">
                            Net value
                            <MoneyText
                                :ngwee="row.net_value_ngwee"
                                class="font-medium text-foreground"
                            />
                            · owes
                            <MoneyText
                                :ngwee="row.shortfall_ngwee"
                                class="font-medium text-foreground"
                            />
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead
                                class="border-b border-border text-xs uppercase text-muted-foreground"
                            >
                                <tr>
                                    <th class="px-3 py-2 text-left">Month</th>
                                    <th class="px-3 py-2 text-right">
                                        Opening
                                    </th>
                                    <th class="px-3 py-2 text-right">
                                        Interest
                                    </th>
                                    <th class="px-3 py-2 text-right">
                                        Minimum repayment
                                    </th>
                                    <th class="px-3 py-2 text-right">
                                        Closing
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="month in row.schedule"
                                    :key="month.month"
                                    class="border-b border-border/60"
                                >
                                    <td class="px-3 py-2">
                                        Month {{ month.month }}
                                    </td>
                                    <td class="tabular px-3 py-2 text-right">
                                        {{ formatMoney(month.opening_ngwee) }}
                                    </td>
                                    <td class="tabular px-3 py-2 text-right">
                                        {{ formatMoney(month.interest_ngwee) }}
                                    </td>
                                    <td
                                        class="tabular px-3 py-2 text-right font-medium"
                                    >
                                        {{ formatMoney(month.repayment_ngwee) }}
                                    </td>
                                    <td class="tabular px-3 py-2 text-right">
                                        {{ formatMoney(month.closing_ngwee) }}
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot class="border-t border-border font-semibold">
                                <tr>
                                    <td class="px-3 py-2" colspan="2">
                                        Total to repay
                                    </td>
                                    <td class="tabular px-3 py-2 text-right">
                                        {{ formatMoney(row.interest_ngwee) }}
                                    </td>
                                    <td class="tabular px-3 py-2 text-right">
                                        {{
                                            formatMoney(
                                                row.total_repayable_ngwee,
                                            )
                                        }}
                                    </td>
                                    <td class="tabular px-3 py-2 text-right">
                                        {{ formatMoney(0) }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </AppCard>

            <p class="text-xs text-muted-foreground">
                Interest is charged on the opening balance and the repayment lands
                after it, which is the order the trading day follows. The level
                payment is rounded up to the ngwee so the balance genuinely reaches
                zero — the last month is usually a little smaller than the others.
            </p>
        </div>
    </AdminLayout>
</template>
