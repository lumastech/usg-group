<script setup lang="ts">
/**
 * The workbook's LOANS sheet: members down the side, months across, the balance each
 * member carried at the end of every month.
 *
 * Nothing is computed here — the grid, its totals and the month labels all arrive from
 * the server, so this screen and the exported sheet can never disagree.
 */
import { router } from '@inertiajs/vue3';
import { Coins, Download, HandCoins, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppCard,
    EmptyState,
    MatrixTable,
    SelectInput,
    StatCard,
} from '@/components/unity';
import type { MatrixColumn } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { LoanMatrix, LoanMatrixRow } from '@/types/loans';

const props = defineProps<{
    matrix: LoanMatrix | null;
    cycle: { id: number; name: string } | null;
    filters: { through: number | null };
}>();

const through = ref<string | number | null>(props.filters.through ?? null);

const columns = computed<MatrixColumn[]>(() =>
    (props.matrix?.months ?? []).map((month) => ({
        key: String(month.id),
        label: month.label,
        sublabel: month.year,
        muted: month.lockdown,
    })),
);

const columnTotals = computed<Record<string, number>>(() => {
    const totals: Record<string, number> = {};

    for (const month of props.matrix?.months ?? []) {
        totals[String(month.id)] =
            props.matrix?.totals.months[month.id]?.balance ?? 0;
    }

    return totals;
});

/** An untouched month reads as empty rather than K0.00, the way the workbook does. */
function cell(row: LoanMatrixRow, column: MatrixColumn): number | null {
    const value = row.cells[Number(column.key)];

    return !value || value.balance === 0 ? null : value.balance;
}

const monthOptions = computed(() => [
    { value: '', label: 'Whole cycle' },
    ...(props.matrix?.months ?? []).map((month) => ({
        value: month.sequence,
        label: `Through ${month.full_label}`,
    })),
]);

function applyFilter(): void {
    router.get(
        '/app/loans/matrix',
        { through: through.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <AdminLayout
        title="Loan matrix"
        heading="Loans workbook"
        :description="cycle ? `${cycle.name} cycle` : undefined"
    >
        <template #actions>
            <a
                :href="`/app/loans/export/xlsx${through ? `?through=${through}` : ''}`"
            >
                <AppButton variant="outline" size="sm">
                    <template #icon><Download class="size-4" /></template>
                    Excel
                </AppButton>
            </a>
            <a
                :href="`/app/loans/export/pdf${through ? `?through=${through}` : ''}`"
            >
                <AppButton variant="outline" size="sm">
                    <template #icon><Download class="size-4" /></template>
                    PDF
                </AppButton>
            </a>
            <SelectInput
                v-model="through"
                :options="monthOptions"
                class="h-9 w-52"
                @change="applyFilter"
            />
        </template>

        <AppCard v-if="!matrix">
            <EmptyState
                title="No active cycle"
                description="Activate a cycle to see its lending."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Lent this cycle"
                    :ngwee="matrix.totals.borrowed_ngwee"
                    :icon="HandCoins"
                    accent="brand"
                />
                <StatCard
                    label="Outstanding"
                    :ngwee="matrix.totals.balance_ngwee"
                    :icon="Coins"
                />
                <StatCard
                    label="Interest collected"
                    :ngwee="matrix.totals.interest_paid_ngwee"
                    :icon="Coins"
                    hint="Shared out to every member"
                />
                <StatCard
                    label="Penalties charged"
                    :ngwee="matrix.totals.penalties_ngwee"
                    :icon="TriangleAlert"
                />
            </div>

            <MatrixTable
                :rows="matrix.rows"
                :columns="columns"
                row-header="Member"
                :row-label="(row) => row.full_name"
                :row-sublabel="(row) => `#${row.member_number}`"
                :cell="cell"
                :totals="columnTotals"
                :row-total="(row) => row.borrowed_ngwee"
                row-total-label="Borrowed"
                :row-key="(row) => row.member_id"
                @cell-click="
                    (row) => router.get(`/app/members/${row.member_id}`)
                "
            />
        </div>
    </AdminLayout>
</template>
