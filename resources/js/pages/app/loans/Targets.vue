<script setup lang="ts">
/**
 * Progress against the borrowing target every member carries for the cycle.
 *
 * The group's income is the interest its members pay, so a cycle where nobody borrows
 * earns nobody anything. The target is tracked and talked about; it blocks nothing, and
 * the red badge is a prompt for a conversation rather than a penalty.
 */
import { router } from '@inertiajs/vue3';
import { Target, TrendingUp, Users } from '@lucide/vue';
import { computed } from 'vue';

import { AppCard, DataTable, StatCard, StatusBadge } from '@/components/unity';
import type { Column } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { BorrowingTargetRow } from '@/types/loans';

const props = defineProps<{
    rows: BorrowingTargetRow[];
    target_ngwee: number;
    totals: {
        borrowed_ngwee: number;
        balance_to_borrow_ngwee: number;
        under_target: number;
    };
}>();

const columns = computed<Column<BorrowingTargetRow>[]>(() => [
    { key: 'full_name', label: 'Member' },
    { key: 'borrowed_ngwee', label: 'Borrowed', numeric: true },
    { key: 'progress', label: 'Progress', hideOnMobile: true },
    { key: 'target_ngwee', label: 'Target', numeric: true, hideOnMobile: true },
    {
        key: 'balance_to_borrow_ngwee',
        label: 'Balance to borrow',
        numeric: true,
    },
]);
</script>

<template>
    <AdminLayout
        title="Borrowing targets"
        heading="Borrowing targets"
        :description="`${formatMoney(target_ngwee)} per member across the cycle`"
    >
        <div class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Borrowed so far"
                    :ngwee="totals.borrowed_ngwee"
                    :icon="TrendingUp"
                    accent="brand"
                />
                <StatCard
                    label="Still to borrow"
                    :ngwee="totals.balance_to_borrow_ngwee"
                    :icon="Target"
                />
                <StatCard
                    label="Under target"
                    :value="totals.under_target"
                    :icon="Users"
                    :hint="`of ${rows.length} members`"
                />
            </div>

            <AppCard flush>
                <DataTable
                    :rows="rows"
                    :columns="columns"
                    :row-key="(row) => row.member_id"
                    empty-title="No members yet"
                    @row-click="
                        (row) => router.get(`/app/members/${row.member_id}`)
                    "
                >
                    <template #cell-full_name="{ row }">
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ row.full_name }}</span>
                            <StatusBadge
                                v-if="row.under_target"
                                status="under target"
                                tone="danger"
                                size="sm"
                            />
                        </div>
                    </template>

                    <template #cell-borrowed_ngwee="{ row }">
                        {{ formatMoney(row.borrowed_ngwee) }}
                    </template>

                    <template #cell-progress="{ row }">
                        <div class="flex items-center gap-2">
                            <div
                                class="h-1.5 w-24 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    class="h-full rounded-full bg-brand-600"
                                    :style="{
                                        width: `${Math.min(100, row.progress_percent)}%`,
                                    }"
                                />
                            </div>
                            <span class="tabular text-xs text-muted-foreground">
                                {{ row.progress_percent }}%
                            </span>
                        </div>
                    </template>

                    <template #cell-target_ngwee="{ row }">
                        {{ formatMoney(row.target_ngwee) }}
                    </template>

                    <template #cell-balance_to_borrow_ngwee="{ row }">
                        {{ formatMoney(row.balance_to_borrow_ngwee) }}
                    </template>
                </DataTable>
            </AppCard>
        </div>
    </AdminLayout>
</template>
