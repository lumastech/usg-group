<script setup lang="ts">
/**
 * The member register.
 *
 * Filtering, sorting and paging all round-trip to the server through DataTable, so
 * this page holds no copy of the register. Row actions render from each member's
 * own `abilities`, which are the policy's answers rather than a permission guess.
 */
import { Link, router } from '@inertiajs/vue3';
import { KeyRound, Pencil, Plus, UserCog, Users } from '@lucide/vue';
import { computed, ref } from 'vue';

import InviteLoginDialog from '@/components/members/InviteLoginDialog.vue';
import {
    AppButton,
    AppCard,
    Can,
    DataTable,
    EmptyState,
    SelectInput,
    StatusBadge,
} from '@/components/unity';
import type { Column, PaginationMeta } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { EnumOption, Member, RegistrationState } from '@/types/members';

const props = defineProps<{
    members: { data: Member[]; meta: PaginationMeta };
    filters: {
        search: string | null;
        status: string | null;
        diaspora: boolean | null;
    };
    sort: { column: string; direction: 'asc' | 'desc' };
    statuses: EnumOption[];
    abilities: { create: boolean; manage: boolean };
    registration: RegistrationState;
}>();

const status = ref<string | null>(props.filters.status);
const diaspora = ref<string | null>(
    props.filters.diaspora === null ? null : String(props.filters.diaspora),
);

const inviting = ref<Member | null>(null);

const columns: Column<Member>[] = [
    {
        key: 'member_number',
        label: '#',
        sortable: true,
        numeric: true,
        width: '4rem',
    },
    { key: 'full_name', label: 'Name', sortable: true },
    { key: 'nrc_number', label: 'NRC', sortable: true, hideOnMobile: true },
    { key: 'phone', label: 'Phone', hideOnMobile: true },
    { key: 'status', label: 'Status', sortable: true },
    {
        key: 'savings_ngwee',
        label: 'Savings',
        numeric: true,
        hideOnMobile: true,
    },
    {
        key: 'loan_balance_ngwee',
        label: 'Loan',
        numeric: true,
        hideOnMobile: true,
    },
];

const statusOptions = computed(() => [
    { value: '', label: 'All statuses' },
    ...props.statuses,
]);

const diasporaOptions = [
    { value: '', label: 'Everyone' },
    { value: 'true', label: 'Diaspora only' },
    { value: 'false', label: 'In Zambia' },
];

/** Filters are server-side too: changing one is a partial reload, not a local filter. */
function applyFilters(): void {
    router.get(
        '/app/members',
        {
            search: props.filters.search ?? undefined,
            status: status.value || undefined,
            diaspora: diaspora.value || undefined,
            sort: props.sort.column,
            direction: props.sort.direction,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <AdminLayout
        title="Members"
        heading="Members"
        :description="`${members.meta.total} registered this cycle`"
    >
        <template v-if="abilities.create" #actions>
            <Link href="/app/members/create">
                <AppButton>
                    <template #icon><Plus class="size-4" /></template>
                    Register member
                </AppButton>
            </Link>
        </template>

        <AppCard
            v-if="abilities.manage && !registration.open"
            class="mb-4 border-gold-200 bg-gold-50/50 dark:border-gold-400/25 dark:bg-gold-400/5"
        >
            <p class="text-sm font-medium text-foreground">
                Registration is closed
            </p>
            <p class="mt-1 text-sm text-muted-foreground">
                Membership closed after month
                {{ registration.closes_after_month }} of the cycle. The register
                can still be corrected, but no new member may be added.
            </p>
        </AppCard>

        <DataTable
            :rows="members.data"
            :columns="columns"
            :meta="members.meta"
            :sort="sort"
            :search="filters.search ?? ''"
            searchable
            search-placeholder="Search name, NRC or phone…"
            empty-title="No members match"
            empty-description="Clear the filters, or register the first member of the cycle."
            @row-click="(row) => router.get(`/app/members/${row.id}`)"
        >
            <template #toolbar>
                <SelectInput
                    v-model="status"
                    :options="statusOptions"
                    class="h-9 w-40"
                    @change="applyFilters"
                />
                <SelectInput
                    v-model="diaspora"
                    :options="diasporaOptions"
                    class="h-9 w-36"
                    @change="applyFilters"
                />
            </template>

            <template #cell-full_name="{ row }">
                <div class="flex items-center gap-2">
                    <Link
                        :href="`/app/members/${row.id}`"
                        class="font-medium hover:text-brand-700"
                    >
                        {{ row.full_name }}
                    </Link>
                    <StatusBadge
                        v-if="row.is_diaspora"
                        status="diaspora"
                        tone="info"
                        size="sm"
                    />
                    <StatusBadge
                        v-if="!row.has_login"
                        status="no login"
                        tone="neutral"
                        size="sm"
                    />
                </div>
            </template>

            <template #cell-status="{ row }">
                <StatusBadge
                    :status="row.status"
                    :label="row.status_label"
                    size="sm"
                />
            </template>

            <template #cell-savings_ngwee="{ row }">
                {{
                    row.savings_ngwee === null
                        ? '—'
                        : formatMoney(row.savings_ngwee)
                }}
            </template>

            <template #cell-loan_balance_ngwee="{ row }">
                {{
                    row.loan_balance_ngwee === null
                        ? '—'
                        : formatMoney(row.loan_balance_ngwee)
                }}
            </template>

            <template #actions="{ row }">
                <div class="flex items-center justify-end gap-1">
                    <Link
                        v-if="row.abilities.update"
                        :href="`/app/members/${row.id}/edit`"
                        @click.stop
                    >
                        <AppButton variant="ghost" size="sm" aria-label="Edit">
                            <template #icon><Pencil class="size-4" /></template>
                        </AppButton>
                    </Link>
                    <Link
                        v-if="row.abilities.changeStatus"
                        :href="`/app/members/${row.id}`"
                        @click.stop
                    >
                        <AppButton
                            variant="ghost"
                            size="sm"
                            aria-label="Change status"
                        >
                            <template #icon
                                ><UserCog class="size-4"
                            /></template>
                        </AppButton>
                    </Link>
                    <AppButton
                        v-if="row.abilities.invite"
                        variant="ghost"
                        size="sm"
                        aria-label="Invite login"
                        @click.stop="inviting = row"
                    >
                        <template #icon><KeyRound class="size-4" /></template>
                    </AppButton>
                </div>
            </template>

            <template
                v-if="members.data.length === 0 && !filters.search"
                #empty
            >
                <EmptyState
                    :icon="Users"
                    title="No members yet"
                    description="Register the group's members, or import the signed commitment sheet."
                >
                    <template v-if="abilities.create" #action>
                        <Link href="/app/members/create"
                            ><AppButton>Register member</AppButton></Link
                        >
                    </template>
                </EmptyState>
            </template>
        </DataTable>

        <Can permission="members.manage">
            <InviteLoginDialog :member="inviting" @close="inviting = null" />
        </Can>
    </AdminLayout>
</template>
