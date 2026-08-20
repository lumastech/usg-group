<script setup lang="ts">
/**
 * The loan register, tabbed by where each loan sits in its life.
 *
 * Filtering, sorting and paging round-trip to the server through DataTable, and every
 * row action renders from that loan's own `abilities` — the policy's answers, not a
 * permission guess made here.
 */
import { Link, router } from '@inertiajs/vue3';
import { HandCoins, Plus, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';

import { AppButton, AppCard, DataTable, StatusBadge } from '@/components/unity';
import type { Column, PaginationMeta } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { Loan } from '@/types/loans';

const props = defineProps<{
    loans: { data: Loan[]; meta: PaginationMeta };
    tab: string;
    tabs: Record<string, number>;
    filters: { search: string | null };
    sort: { column: string; direction: 'asc' | 'desc' };
    abilities: { create: boolean };
}>();

/** The tabs follow the order lending actually moves through, not the enum's order. */
const TABS: { key: string; label: string }[] = [
    { key: 'requested', label: 'Requested' },
    { key: 'approved', label: 'Approved' },
    { key: 'running', label: 'Disbursed & repaying' },
    { key: 'settled', label: 'Settled' },
    { key: 'defaulted', label: 'Defaulted' },
    { key: 'rejected', label: 'Rejected' },
];

const columns = computed<Column<Loan>[]>(() => [
    { key: 'member_name', label: 'Member' },
    {
        key: 'principal_ngwee',
        label: 'Principal',
        numeric: true,
        sortable: true,
    },
    { key: 'balance_ngwee', label: 'Balance', numeric: true, sortable: true },
    { key: 'next_due', label: 'Next due', hideOnMobile: true },
    { key: 'status', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
]);

function switchTab(tab: string): void {
    router.get(
        '/app/loans',
        { tab },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <AdminLayout
        title="Loans"
        heading="Loans"
        description="Every loan this cycle, from request to settlement"
    >
        <template #actions>
            <Link href="/app/loans/matrix">
                <AppButton variant="outline" size="sm">Workbook view</AppButton>
            </Link>
            <Link href="/app/loans/targets">
                <AppButton variant="outline" size="sm">Targets</AppButton>
            </Link>
            <Link v-if="abilities.create" href="/app/loans/request">
                <AppButton size="sm">
                    <template #icon><Plus class="size-4" /></template>
                    New request
                </AppButton>
            </Link>
        </template>

        <div class="space-y-5">
            <!-- Tab bar. Counts come from the server, so it reads without a second visit. -->
            <AppCard flush class="px-2 py-2">
                <nav class="flex scrollbar-thin gap-1 overflow-x-auto">
                    <button
                        v-for="item in TABS"
                        :key="item.key"
                        type="button"
                        :class="[
                            'flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                            item.key === tab
                                ? 'bg-brand-600 text-white'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        ]"
                        :aria-current="item.key === tab ? 'page' : undefined"
                        @click="switchTab(item.key)"
                    >
                        {{ item.label }}
                        <span
                            :class="[
                                'tabular rounded-full px-1.5 py-0.5 text-xs',
                                item.key === tab
                                    ? 'bg-white/20'
                                    : 'bg-muted text-muted-foreground',
                            ]"
                        >
                            {{ tabs[item.key] ?? 0 }}
                        </span>
                    </button>
                </nav>
            </AppCard>

            <DataTable
                :rows="loans.data"
                :columns="columns"
                :meta="loans.meta"
                :sort="sort"
                :search="filters.search ?? ''"
                searchable
                search-placeholder="Search by member name…"
                :only="['loans', 'sort', 'filters']"
                empty-title="No loans here"
                empty-description="Nothing sits under this tab yet."
                @row-click="(row) => router.get(`/app/loans/${row.id}`)"
            >
                <template #cell-member_name="{ row }">
                    <div class="flex items-center gap-2">
                        <Link
                            :href="`/app/loans/${row.id}`"
                            class="font-medium hover:text-brand-700"
                        >
                            {{ row.member_name }}
                        </Link>
                        <StatusBadge
                            v-if="row.discretion_override"
                            status="discretion"
                            tone="info"
                            size="sm"
                        />
                        <StatusBadge
                            v-if="row.schedule_compressed"
                            status="compressed"
                            tone="warning"
                            size="sm"
                        />
                    </div>
                </template>

                <template #cell-principal_ngwee="{ row }">
                    {{ formatMoney(row.principal_ngwee) }}
                </template>

                <template #cell-balance_ngwee="{ row }">
                    {{ formatMoney(row.balance_ngwee) }}
                </template>

                <template #cell-next_due="{ row }">
                    <div
                        v-if="row.next_due_on"
                        class="flex items-center gap-2 whitespace-nowrap"
                    >
                        <span>{{ row.next_due_on }}</span>
                        <span
                            v-if="row.days_late > 0"
                            class="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-700 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/25"
                        >
                            <TriangleAlert class="size-3" />
                            {{ row.days_late }}d late
                        </span>
                    </div>
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #cell-status="{ row }">
                    <StatusBadge
                        :status="row.status"
                        :label="row.status_label"
                        size="sm"
                    />
                </template>

                <template #cell-actions="{ row }">
                    <Link :href="`/app/loans/${row.id}`">
                        <AppButton variant="ghost" size="sm">
                            <template #icon
                                ><HandCoins class="size-4"
                            /></template>
                            Open
                        </AppButton>
                    </Link>
                </template>
            </DataTable>
        </div>
    </AdminLayout>
</template>
