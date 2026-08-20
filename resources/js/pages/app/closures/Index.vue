<script setup lang="ts">
/**
 * The closures register: who is still to be settled, and what each would come to.
 *
 * Every figure here is computed fresh from the ledgers by the server — nothing on
 * this screen is a stored total. Departures sort above the members still standing,
 * because a family waiting on a death settlement should not be at the bottom of a
 * list of thirty.
 */
import { router } from '@inertiajs/vue3';
import { Coins, TriangleAlert, UserMinus, Users } from '@lucide/vue';
import { computed } from 'vue';

import {
    AppCard,
    DataTable,
    MoneyText,
    StatCard,
    StatusBadge,
} from '@/components/unity';
import type { Column } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { ClosureCycle, ClosureRow } from '@/types/payouts';

const props = defineProps<{
    cycle: ClosureCycle | null;
    pending: ClosureRow[];
    settled: ClosureRow[];
}>();

const columns: Column<ClosureRow>[] = [
    { key: 'member_number', label: '#', width: '4rem' },
    { key: 'full_name', label: 'Member' },
    { key: 'case_label', label: 'Case' },
    { key: 'effective', label: 'Since', hideOnMobile: true },
    { key: 'net_value_ngwee', label: 'Net value', numeric: true },
    { key: 'net_payable_ngwee', label: 'Net payable', numeric: true },
    { key: 'outcome', label: '', align: 'right' },
];

const exits = computed(() =>
    props.pending.filter((row) => row.status !== 'active'),
);

const underWater = computed(() =>
    props.pending.filter((row) => row.is_negative),
);

const owing = computed(() =>
    underWater.value.reduce((sum, row) => sum + row.net_payable_ngwee, 0),
);

const payable = computed(() =>
    props.pending
        .filter((row) => !row.is_negative)
        .reduce((sum, row) => sum + row.net_payable_ngwee, 0),
);

function open(row: ClosureRow): void {
    router.get(`/app/closures/${row.member_id}`);
}
</script>

<template>
    <AdminLayout
        title="Closures"
        heading="Closures"
        description="Settling members out of the cycle. Every position is computed from the ledgers, and two committee members sign for each one."
    >
        <div class="space-y-5">
            <div
                v-if="cycle && !cycle.is_sharing_out"
                class="flex items-start gap-3 rounded-lg border border-gold-200 bg-gold-50/60 p-4 text-sm dark:border-gold-400/25 dark:bg-gold-400/5"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0 text-gold-700 dark:text-gold-300" />
                <p class="text-muted-foreground">
                    The cycle is
                    <span class="font-medium text-foreground">{{ cycle.status_label }}</span
                    >. Closures are settled at share-out, so these figures are a
                    preview. A death may still be settled early, with a written
                    reason, on the member's own page.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Awaiting settlement"
                    :value="pending.length"
                    :icon="Users"
                    :hint="`${exits.length} of them exits`"
                />
                <StatCard
                    label="Payable"
                    :ngwee="payable"
                    :icon="Coins"
                    accent="gold"
                    hint="Members in credit"
                />
                <StatCard
                    label="Under water"
                    :ngwee="owing"
                    :icon="UserMinus"
                    :hint="`${underWater.length} owing the group`"
                />
            </div>

            <AppCard
                title="Pending closures"
                description="Exits first, then the members still standing."
                flush
            >
                <DataTable
                    :rows="pending"
                    :columns="columns"
                    row-key="member_id"
                    empty-title="Nobody is waiting to be settled"
                    empty-description="Every member of this cycle has been closed out."
                    @row-click="open"
                >
                    <template #cell-full_name="{ row }">
                        <span class="font-medium text-foreground">{{ row.full_name }}</span>
                    </template>

                    <template #cell-case_label="{ row }">
                        <StatusBadge :status="row.status" :label="row.case_label" size="sm" />
                    </template>

                    <template #cell-effective="{ row }">
                        <span class="text-muted-foreground">
                            {{ row.date_of_death ?? row.status_effective_on ?? '—' }}
                        </span>
                    </template>

                    <template #cell-net_value_ngwee="{ row }">
                        <MoneyText :ngwee="row.net_value_ngwee" />
                    </template>

                    <template #cell-net_payable_ngwee="{ row }">
                        <MoneyText :ngwee="row.net_payable_ngwee" signed />
                    </template>

                    <template #cell-outcome="{ row }">
                        <span
                            v-if="row.is_negative"
                            class="text-xs font-medium text-destructive"
                        >
                            Owes the group
                        </span>
                        <span
                            v-else-if="row.funeral_grant"
                            class="text-xs text-muted-foreground"
                        >
                            Funeral grant {{ row.funeral_grant.status_label.toLowerCase() }}
                        </span>
                    </template>
                </DataTable>
            </AppCard>

            <AppCard
                v-if="settled.length"
                title="Settled"
                description="Ledgers closed. Nothing may be posted against these members again."
                flush
            >
                <DataTable
                    :rows="settled"
                    :columns="columns"
                    row-key="member_id"
                    empty-title="Nobody has been settled yet"
                    @row-click="open"
                >
                    <template #cell-full_name="{ row }">
                        <span class="font-medium text-foreground">{{ row.full_name }}</span>
                    </template>

                    <template #cell-case_label="{ row }">
                        <StatusBadge :status="row.status" :label="row.case_label" size="sm" />
                    </template>

                    <template #cell-effective="{ row }">
                        <span class="text-muted-foreground">
                            {{ row.date_of_death ?? row.status_effective_on ?? '—' }}
                        </span>
                    </template>

                    <template #cell-net_value_ngwee="{ row }">
                        <MoneyText :ngwee="row.net_value_ngwee" />
                    </template>

                    <template #cell-net_payable_ngwee="{ row }">
                        <MoneyText :ngwee="row.net_payable_ngwee" signed />
                    </template>

                    <template #cell-outcome="{ row }">
                        <span class="text-xs text-muted-foreground">
                            {{ row.settled_at?.slice(0, 10) }}
                        </span>
                    </template>
                </DataTable>
            </AppCard>
        </div>
    </AdminLayout>
</template>
