<script setup lang="ts">
/**
 * Every line of the fund's ledger, filterable by type and by member, with the
 * workbook-style export beside it.
 *
 * Sorting, filtering and pagination are the server's; this page only asks.
 */
import { router } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowUpRight, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    Can,
    ClientOnly,
    ConfirmDialog,
    DataTable,
    FormField,
    Modal,
    MoneyInput,
    SelectInput,
    StatCard,
    StatusBadge,
    TextareaInput,
} from '@/components/unity';
import type { Column, PaginationMeta, SelectOption } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { formatMoney } from '@/lib/money';
import type { FundEntry, FundOverview } from '@/types/fund';

const props = defineProps<{
    entries: { data: FundEntry[]; meta: PaginationMeta | null };
    overview: FundOverview | null;
    types: SelectOption[];
    members: SelectOption[];
    filters: {
        type: string | null;
        member_id: number | null;
        search: string | null;
    };
    sort: { column: string; direction: 'asc' | 'desc' };
}>();

const type = ref<string | number | null>(props.filters.type);
const memberId = ref<string | number | null>(props.filters.member_id);
const entryOpen = ref(false);
const confirmOpen = ref(false);

const form = useForm({
    type: 'gathering_expense',
    amount_ngwee: null as number | null,
    member_id: null as number | null,
    occurred_on: new Date().toISOString().slice(0, 10),
    note: '',
    approver_email: '',
    approver_password: '',
});

const columns = computed<Column<FundEntry>[]>(() => [
    { key: 'occurred_on', label: 'Date', sortable: true },
    { key: 'type', label: 'Type', sortable: true },
    { key: 'member', label: 'Member', hideOnMobile: true },
    { key: 'amount_ngwee', label: 'Amount', sortable: true, numeric: true },
    { key: 'approvers', label: 'Signatures', hideOnMobile: true },
    { key: 'note', label: 'Note', hideOnMobile: true },
]);

/** Money leaving the fund is entered as a negative amount, so it needs both names. */
const amountHint = computed(() =>
    form.type === 'adjustment'
        ? 'A negative amount reduces the fund and needs the second signature.'
        : 'Enter what left the fund as a negative amount.',
);

function applyFilters(): void {
    router.get(
        '/app/fund/ledger',
        {
            type: type.value || undefined,
            member_id: memberId.value || undefined,
            search: props.filters.search || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function download(): void {
    window.location.href = '/app/fund/export/xlsx';
}

function submit(payload: {
    approver_email?: string;
    approver_password?: string;
}): void {
    form.approver_email = payload.approver_email ?? '';
    form.approver_password = payload.approver_password ?? '';

    form.post('/app/fund/entries', {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
            entryOpen.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Social fund ledger"
        heading="Social fund ledger"
        description="Append-only. A correction is a reversing entry, never an edit."
    >
        <div class="space-y-5">
            <div v-if="overview" class="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Balance"
                    :ngwee="overview.balance_ngwee"
                    :icon="Wallet"
                    accent="gold"
                />
                <StatCard
                    label="Received"
                    :ngwee="overview.inflow_ngwee"
                    :icon="ArrowUpRight"
                />
                <StatCard
                    label="Paid out"
                    :ngwee="overview.outflow_ngwee"
                    :icon="ArrowDownRight"
                />
            </div>

            <AppCard flush>
                <template #header>
                    <div class="flex flex-wrap items-end gap-3">
                        <div class="w-44">
                            <FormField label="Type">
                                <SelectInput
                                    v-model="type"
                                    :options="types"
                                    placeholder="All types"
                                    @change="applyFilters"
                                />
                            </FormField>
                        </div>
                        <div class="w-56">
                            <FormField label="Member">
                                <SelectInput
                                    v-model="memberId"
                                    :options="members"
                                    placeholder="Everyone"
                                    @change="applyFilters"
                                />
                            </FormField>
                        </div>
                        <AppButton
                            variant="ghost"
                            size="sm"
                            @click="applyFilters"
                        >
                            Apply
                        </AppButton>
                    </div>
                </template>

                <template #actions>
                    <Can permission="fund.approve-outflow">
                        <AppButton size="sm" @click="entryOpen = true">
                            Post an entry
                        </AppButton>
                    </Can>
                </template>

                <DataTable
                    :rows="entries.data"
                    :columns="columns"
                    :meta="entries.meta ?? undefined"
                    :sort="sort"
                    :search="filters.search ?? ''"
                    searchable
                    search-placeholder="Search notes and members…"
                    exportable
                    empty-title="No entries yet"
                    empty-description="Contributions, penalties, grants and gatherings all land here."
                    @export="download"
                >
                    <template #cell-type="{ row }">
                        <StatusBadge
                            :status="row.type"
                            :label="row.type_label"
                            :tone="row.is_outflow ? 'warning' : 'success'"
                            size="sm"
                        />
                    </template>

                    <template #cell-member="{ row }">
                        {{ row.member ?? '—' }}
                    </template>

                    <template #cell-amount_ngwee="{ row }">
                        <span
                            class="tabular font-medium"
                            :class="
                                row.is_outflow
                                    ? 'text-red-600 dark:text-red-400'
                                    : ''
                            "
                        >
                            {{ formatMoney(row.amount_ngwee) }}
                        </span>
                    </template>

                    <template #cell-approvers="{ row }">
                        <span class="text-xs text-muted-foreground">
                            {{ row.recorded_by ?? 'System' }}
                            <template v-if="row.second_approver">
                                + {{ row.second_approver }}
                            </template>
                        </span>
                    </template>

                    <template #cell-note="{ row }">
                        <span class="text-xs text-muted-foreground">
                            {{ row.note ?? '—' }}
                        </span>
                    </template>
                </DataTable>
            </AppCard>
        </div>

        <ClientOnly>
            <Modal
                v-model:open="entryOpen"
                title="Post a social fund entry"
                description="For gathering expenses and corrections. Grants and apportionments have their own screens."
            >
                <div class="space-y-4">
                    <FormField label="Type" :error="form.errors.type" required>
                        <SelectInput
                            v-model="form.type"
                            :options="
                                types.filter((option) =>
                                    [
                                        'gathering_expense',
                                        'adjustment',
                                    ].includes(String(option.value)),
                                )
                            "
                        />
                    </FormField>

                    <FormField
                        label="Amount"
                        :error="form.errors.amount_ngwee"
                        :hint="amountHint"
                        required
                    >
                        <MoneyInput
                            v-model="form.amount_ngwee"
                            :steppers="false"
                        />
                    </FormField>

                    <FormField
                        label="Member"
                        :error="form.errors.member_id"
                        hint="Optional — leave blank for a group expense."
                    >
                        <SelectInput
                            v-model="form.member_id"
                            :options="members"
                            placeholder="No member"
                        />
                    </FormField>

                    <FormField label="Dated" :error="form.errors.occurred_on">
                        <input
                            v-model="form.occurred_on"
                            type="date"
                            class="h-10 w-full rounded-lg border border-input bg-card px-3 text-sm"
                        />
                    </FormField>

                    <FormField label="Note" :error="form.errors.note">
                        <TextareaInput v-model="form.note" :rows="2" />
                    </FormField>
                </div>

                <template #footer>
                    <AppButton variant="ghost" @click="entryOpen = false">
                        Cancel
                    </AppButton>
                    <AppButton
                        :disabled="!form.amount_ngwee"
                        @click="confirmOpen = true"
                    >
                        Continue
                    </AppButton>
                </template>
            </Modal>

            <ConfirmDialog
                v-model:open="confirmOpen"
                variant="dual-approval"
                title="Confirm this entry"
                :action-summary="
                    form.amount_ngwee
                        ? `Post ${formatMoney(form.amount_ngwee)} to the social fund`
                        : undefined
                "
                message="Money may only leave the fund with a second committee member confirming it here."
                confirm-label="Post entry"
                :errors="form.errors as Record<string, string>"
                :processing="form.processing"
                @confirm="submit"
            />
        </ClientOnly>
    </AdminLayout>
</template>
