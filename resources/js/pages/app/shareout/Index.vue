<script setup lang="ts">
/**
 * The SHARE OUT sheet: what every member walks away with.
 *
 * A pixel-mirror of the workbook page the group has read out at the end of every
 * cycle — savings and interest per month across the top, then the six closing columns
 * — so a member holding last year's printout can follow along. Every figure comes from
 * the server's ShareOutSheet, which is the same service the Excel and PDF exports
 * render; nothing here recomputes a total.
 */
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    Coins,
    Download,
    FileText,
    TriangleAlert,
    UserMinus,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    ConfirmDialog,
    EmptyState,
    MatrixTable,
    MoneyText,
    StatCard,
} from '@/components/unity';
import type { MatrixColumn } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    ShareOutBatch,
    ShareOutCycle,
    ShareOutRow,
    ShareOutSheet,
} from '@/types/shareout';

const props = defineProps<{
    cycle: ShareOutCycle | null;
    sheet: ShareOutSheet | null;
    batch?: ShareOutBatch | null;
    abilities: { runBatch: boolean };
}>();

const batchOpen = ref(false);

const form = useForm({ note: '', approver_email: '', approver_password: '' });

/** Two columns per month, savings then interest, exactly as the workbook has them. */
const columns = computed<MatrixColumn[]>(() =>
    (props.sheet?.months ?? []).flatMap((month) => [
        {
            key: `${month.id}:savings`,
            label: month.label,
            sublabel: 'Savings',
        },
        {
            key: `${month.id}:interest`,
            label: month.label,
            sublabel: 'Interest',
            muted: true,
        },
    ]),
);

const columnTotals = computed<Record<string, number>>(() => {
    const totals: Record<string, number> = {};

    for (const month of props.sheet?.months ?? []) {
        const cell = props.sheet?.totals.months[month.id];

        totals[`${month.id}:savings`] = cell?.savings ?? 0;
        totals[`${month.id}:interest`] = cell?.interest ?? 0;
    }

    return totals;
});

function cell(row: ShareOutRow, column: MatrixColumn): number | null {
    const [monthId, part] = column.key.split(':');
    const values = row.cells[Number(monthId)];

    if (!values) {
        return null;
    }

    return part === 'savings' ? values.savings : values.interest;
}

const underWater = computed(
    () => props.sheet?.rows.filter((row) => row.is_negative) ?? [],
);

function runBatch(payload: {
    approver_email?: string;
    approver_password?: string;
}): void {
    form.transform((data) => ({
        note: data.note,
        approver_email: payload.approver_email ?? '',
        approver_password: payload.approver_password ?? '',
    })).post('/app/shareout/batch', {
        preserveScroll: true,
        onSuccess: () => {
            batchOpen.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Share-out"
        heading="Share-out"
        description="Total savings, interest earned, what is still owed, and what each member is handed."
    >
        <AppCard v-if="!cycle || !sheet">
            <EmptyState
                title="No active cycle"
                description="Activate a cycle to see the share-out sheet."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <div
                v-if="!cycle.is_sharing_out"
                class="flex items-start gap-3 rounded-lg border border-gold-200 bg-gold-50/60 p-4 text-sm dark:border-gold-400/25 dark:bg-gold-400/5"
            >
                <TriangleAlert
                    class="mt-0.5 size-4 shrink-0 text-gold-700 dark:text-gold-300"
                />
                <p class="text-muted-foreground">
                    The cycle is
                    <span class="font-medium text-foreground">{{
                        cycle.status_label
                    }}</span>
                    , so this sheet is a preview. Work the
                    <Link
                        href="/app/shareout/preflight"
                        class="font-medium text-brand-600 hover:underline dark:text-brand-400"
                        >pre-flight checklist</Link
                    >
                    before anybody is paid.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Total savings"
                    :ngwee="sheet.totals.total_savings_ngwee"
                    accent="brand"
                    :hint="`${sheet.totals.members} members`"
                />
                <StatCard
                    label="Total interest"
                    :ngwee="sheet.totals.total_interest_ngwee"
                    :icon="Coins"
                    hint="Pooled and shared by savings"
                />
                <StatCard
                    label="Net payable"
                    :ngwee="sheet.totals.payable_ngwee"
                    :icon="Users"
                    accent="gold"
                    :hint="`${sheet.totals.settled} already settled`"
                />
                <StatCard
                    label="Shortfalls"
                    :ngwee="sheet.totals.shortfall_ngwee"
                    :icon="UserMinus"
                    :hint="`${underWater.length} members under water`"
                />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <AppButton
                    as="a"
                    href="/app/shareout/export/xlsx"
                    variant="secondary"
                >
                    <Download class="size-4" />
                    Excel
                </AppButton>
                <AppButton
                    as="a"
                    href="/app/shareout/export/pdf"
                    variant="secondary"
                >
                    <FileText class="size-4" />
                    PDF
                </AppButton>
                <AppButton
                    as="a"
                    href="/app/shareout/schedule"
                    variant="secondary"
                >
                    <FileText class="size-4" />
                    Payout schedule
                </AppButton>

                <AppButton
                    v-if="abilities.runBatch"
                    class="ms-auto"
                    @click="batchOpen = true"
                >
                    Run the share-out batch
                </AppButton>
            </div>

            <MatrixTable
                :rows="sheet.rows"
                :columns="columns"
                row-header="Member"
                :row-label="(row) => row.full_name"
                :row-sublabel="(row) => `#${row.member_number}`"
                :row-key="(row) => row.member_id"
                :cell="cell"
                :totals="columnTotals"
                :row-total="(row) => row.net_payable_ngwee"
                row-total-label="Net payable"
                class="max-h-[60vh]"
                @cell-click="
                    (row) => router.visit(`/app/closures/${row.member_id}`)
                "
            />

            <!-- The closing columns, kept as their own table: the matrix scrolls
                 horizontally and these six are what the room is listening for. -->
            <AppCard title="Closing columns" flush>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="border-b border-border text-xs uppercase text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2 text-left">Member</th>
                                <th class="px-3 py-2 text-left">Case</th>
                                <th class="px-3 py-2 text-right">
                                    Total savings
                                </th>
                                <th class="px-3 py-2 text-right">
                                    Total interest
                                </th>
                                <th class="px-3 py-2 text-right">
                                    Outstanding loan
                                </th>
                                <th class="px-3 py-2 text-right">Net value</th>
                                <th class="px-3 py-2 text-right">Round-off</th>
                                <th class="px-3 py-2 text-right">
                                    Net payable
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in sheet.rows"
                                :key="row.member_id"
                                class="border-b border-border/60"
                            >
                                <td class="px-3 py-2">
                                    <Link
                                        :href="`/app/closures/${row.member_id}`"
                                        class="font-medium hover:underline"
                                    >
                                        {{ row.full_name }}
                                    </Link>
                                </td>
                                <td
                                    class="px-3 py-2 text-xs text-muted-foreground"
                                >
                                    {{ row.case_label }}
                                </td>
                                <td class="tabular px-3 py-2 text-right">
                                    <MoneyText
                                        :ngwee="row.total_savings_ngwee"
                                    />
                                </td>
                                <td class="tabular px-3 py-2 text-right">
                                    <MoneyText
                                        :ngwee="row.total_interest_ngwee"
                                    />
                                </td>
                                <td class="tabular px-3 py-2 text-right">
                                    <MoneyText
                                        :ngwee="row.outstanding_loan_ngwee"
                                    />
                                </td>
                                <td class="tabular px-3 py-2 text-right">
                                    <MoneyText :ngwee="row.net_value_ngwee" />
                                </td>
                                <td class="tabular px-3 py-2 text-right">
                                    <MoneyText :ngwee="row.round_off_ngwee" />
                                </td>
                                <td
                                    class="tabular px-3 py-2 text-right font-medium"
                                >
                                    <MoneyText :ngwee="row.net_payable_ngwee" />
                                </td>
                            </tr>
                        </tbody>
                        <tfoot
                            class="border-t border-border font-semibold text-foreground"
                        >
                            <tr>
                                <td class="px-3 py-2" colspan="2">Total</td>
                                <td class="tabular px-3 py-2 text-right">
                                    {{
                                        formatMoney(
                                            sheet.totals.total_savings_ngwee,
                                        )
                                    }}
                                </td>
                                <td class="tabular px-3 py-2 text-right">
                                    {{
                                        formatMoney(
                                            sheet.totals.total_interest_ngwee,
                                        )
                                    }}
                                </td>
                                <td class="tabular px-3 py-2 text-right">
                                    {{
                                        formatMoney(
                                            sheet.totals.outstanding_loan_ngwee,
                                        )
                                    }}
                                </td>
                                <td class="tabular px-3 py-2 text-right">
                                    {{
                                        formatMoney(
                                            sheet.totals.net_value_ngwee,
                                        )
                                    }}
                                </td>
                                <td class="tabular px-3 py-2 text-right">
                                    {{
                                        formatMoney(
                                            sheet.totals.round_off_ngwee,
                                        )
                                    }}
                                </td>
                                <td class="tabular px-3 py-2 text-right">
                                    {{
                                        formatMoney(
                                            sheet.totals.net_payable_ngwee,
                                        )
                                    }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </AppCard>

            <AppCard
                v-if="abilities.runBatch"
                title="Batch runner"
                description="Every member still standing, settled one at a time under the same two signatures."
            >
                <div v-if="batch === undefined" class="space-y-2">
                    <div
                        v-for="n in 4"
                        :key="n"
                        class="h-8 animate-pulse rounded bg-muted"
                    />
                </div>

                <p
                    v-else-if="!batch || batch.candidates.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Nobody is left to settle in the batch.
                </p>

                <p v-else class="text-sm text-muted-foreground">
                    {{ batch.candidates.length }} member(s) waiting,
                    {{ formatMoney(batch.schedule.total_ngwee) }} already paid
                    across {{ batch.schedule.count }} voucher(s).
                </p>
            </AppCard>

            <p class="text-xs text-muted-foreground">
                Amounts in Kwacha. Members who left early or were expelled forfeit
                their interest and an estate's interest stops at the date of death —
                the interest earned is still shown, but it is not carried into net
                value. Select a row to open that member's closure.
            </p>
        </div>

        <ClientOnly>
            <ConfirmDialog
                v-model:open="batchOpen"
                title="Run the share-out batch"
                variant="dual-approval"
                confirm-label="Settle every member"
                :action-summary="`Settle ${batch?.candidates.length ?? 0} member(s) and freeze their ledgers`"
                :errors="form.errors"
                :processing="form.processing"
                @confirm="runBatch"
                @cancel="batchOpen = false"
            />
        </ClientOnly>
    </AdminLayout>
</template>
