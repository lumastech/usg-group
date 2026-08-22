<script setup lang="ts">
/**
 * Every payment the group has asked for or sent.
 *
 * The three figures at the top are the ones that matter: money still in flight, money
 * the provider moved that the ledgers have not taken, and money somebody has to make a
 * decision about. The last of those is the only one that is ever a problem — the other
 * two clear themselves.
 *
 * Actions render from the `abilities` the server computed per row, so a button is never
 * shown for something the policy would refuse.
 */
import { router } from '@inertiajs/vue3';
import {
    CircleAlert,
    Clock,
    RefreshCw,
    RotateCcw,
    Send,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    DataTable,
    FormField,
    Modal,
    MoneyText,
    SelectInput,
    StatCard,
    StatusBadge,
    TextareaInput,
} from '@/components/unity';
import type { Column, PaginationMeta } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { PaymentIntent, PaymentSummary } from '@/types/payments';

const props = defineProps<{
    payments: { data: PaymentIntent[]; meta: PaginationMeta | null };
    summary: PaymentSummary;
    options: {
        statuses: { value: string; label: string }[];
        directions: { value: string; label: string }[];
        purposes: { value: string; label: string }[];
    };
    members: { value: number; label: string }[];
    filters: {
        status: string | null;
        direction: string | null;
        purpose: string | null;
        member_id: number | null;
        search: string | null;
    };
    sort: { column: string; direction: 'asc' | 'desc' };
}>();

const columns: Column<PaymentIntent>[] = [
    { key: 'created_at', label: 'When', sortable: true },
    { key: 'member_name', label: 'Member' },
    { key: 'purpose_label', label: 'For' },
    { key: 'amount_ngwee', label: 'Amount', sortable: true, numeric: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'reference', label: 'Reference', hideOnMobile: true },
    { key: 'actions', label: '', align: 'right' },
];

const resolving = ref<PaymentIntent | null>(null);
const resolveNote = ref('');

const needsAttention = computed(() =>
    props.payments.data.filter((row) => row.status === 'needs-attention'),
);

function filter(key: string, value: string | number | null): void {
    router.get(
        '/app/payments',
        { ...props.filters, [key]: value === '' ? null : value },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function refresh(payment: PaymentIntent): void {
    router.post(
        `/app/payments/${payment.id}/refresh`,
        {},
        { preserveScroll: true },
    );
}

function retry(payment: PaymentIntent): void {
    router.post(
        `/app/payments/${payment.id}/retry`,
        {},
        { preserveScroll: true },
    );
}

function resolve(action: 'post' | 'set-aside'): void {
    if (!resolving.value) {
        return;
    }

    router.post(
        `/app/payments/${resolving.value.id}/resolve`,
        { action, note: resolveNote.value },
        {
            preserveScroll: true,
            onFinish: () => {
                resolving.value = null;
                resolveNote.value = '';
            },
        },
    );
}

function when(value: string | null): string {
    return value ? new Date(value).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}
</script>

<template>
    <AdminLayout
        title="Payments"
        heading="Payments"
        description="Money the group has asked for, and money it has sent."
    >
        <div class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="In flight"
                    :value="summary.in_flight"
                    :icon="Clock"
                    hint="Waiting on a member or on the network"
                />
                <StatCard
                    label="Not yet in the books"
                    :value="summary.unposted"
                    :icon="Send"
                    :accent="summary.unposted > 0 ? 'gold' : 'none'"
                    hint="Money moved, ledgers still to take it"
                />
                <StatCard
                    label="Needs attention"
                    :value="summary.needs_attention"
                    :icon="CircleAlert"
                    :accent="summary.needs_attention > 0 ? 'gold' : 'none'"
                    hint="Somebody has to decide"
                />
                <StatCard
                    label="Fees this cycle"
                    :ngwee="summary.fees_ngwee"
                    :icon="Wallet"
                    hint="Members bear the fee on money in"
                />
            </div>

            <AppCard
                v-if="needsAttention.length"
                title="Money the ledgers would not take"
                description="The provider says this money moved. Each one needs a decision — record it now that whatever blocked it is dealt with, or set it aside as handled another way."
                class="border-l-4 border-l-gold-500"
            >
                <ul class="space-y-2">
                    <li
                        v-for="row in needsAttention"
                        :key="row.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-md bg-muted/40 p-3"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ row.member_name ?? 'The group' }} —
                                <MoneyText :ngwee="row.amount_ngwee" />
                                <span class="text-muted-foreground">
                                    ({{ row.purpose_label }})</span
                                >
                            </p>
                            <p class="text-sm text-gold-700 dark:text-gold-300">
                                {{ row.status_reason }}
                            </p>
                        </div>
                        <AppButton
                            v-if="row.abilities.resolve"
                            size="sm"
                            variant="secondary"
                            @click="resolving = row"
                        >
                            Decide
                        </AppButton>
                    </li>
                </ul>
            </AppCard>

            <AppCard flush>
                <div class="grid gap-3 border-b p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Status">
                        <template #default="{ id }">
                            <SelectInput
                                :id="id"
                                :model-value="filters.status ?? ''"
                                :options="[
                                    { value: '', label: 'Any status' },
                                    ...options.statuses,
                                ]"
                                @update:model-value="
                                    filter('status', $event as string)
                                "
                            />
                        </template>
                    </FormField>
                    <FormField label="Direction">
                        <template #default="{ id }">
                            <SelectInput
                                :id="id"
                                :model-value="filters.direction ?? ''"
                                :options="[
                                    { value: '', label: 'In and out' },
                                    ...options.directions,
                                ]"
                                @update:model-value="
                                    filter('direction', $event as string)
                                "
                            />
                        </template>
                    </FormField>
                    <FormField label="For">
                        <template #default="{ id }">
                            <SelectInput
                                :id="id"
                                :model-value="filters.purpose ?? ''"
                                :options="[
                                    { value: '', label: 'Anything' },
                                    ...options.purposes,
                                ]"
                                @update:model-value="
                                    filter('purpose', $event as string)
                                "
                            />
                        </template>
                    </FormField>
                    <FormField label="Member">
                        <template #default="{ id }">
                            <SelectInput
                                :id="id"
                                :model-value="String(filters.member_id ?? '')"
                                :options="[
                                    { value: '', label: 'Everybody' },
                                    ...members.map((m) => ({
                                        value: String(m.value),
                                        label: m.label,
                                    })),
                                ]"
                                @update:model-value="
                                    filter(
                                        'member_id',
                                        ($event as string) || null,
                                    )
                                "
                            />
                        </template>
                    </FormField>
                </div>

                <DataTable
                    :rows="payments.data"
                    :columns="columns"
                    :meta="payments.meta ?? undefined"
                    :sort="sort"
                    :search="filters.search ?? ''"
                    searchable
                    search-placeholder="Reference or member…"
                    :only="['payments', 'summary', 'filters', 'sort']"
                    row-key="id"
                    empty-title="No payments yet"
                    empty-description="Nothing has been asked for or sent through the gateway."
                >
                    <template #cell-created_at="{ row }">
                        <span class="text-sm text-muted-foreground">{{
                            when(row.created_at)
                        }}</span>
                    </template>

                    <template #cell-member_name="{ row }">
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                {{ row.member_name ?? 'The group' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ row.direction_label }} ·
                                {{ row.channel_label }}
                            </p>
                        </div>
                    </template>

                    <template #cell-amount_ngwee="{ row }">
                        <MoneyText :ngwee="row.amount_ngwee" />
                        <p
                            v-if="row.fee_ngwee"
                            class="text-xs text-muted-foreground"
                        >
                            fee <MoneyText :ngwee="row.fee_ngwee" />
                        </p>
                    </template>

                    <template #cell-status="{ row }">
                        <StatusBadge
                            :status="row.status"
                            :label="row.status_label"
                        />
                        <p
                            v-if="row.status_reason"
                            class="mt-1 max-w-xs text-xs text-muted-foreground"
                        >
                            {{ row.status_reason }}
                        </p>
                    </template>

                    <template #cell-reference="{ row }">
                        <span class="font-mono text-xs text-muted-foreground">{{
                            row.reference
                        }}</span>
                    </template>

                    <template #cell-actions="{ row }">
                        <div class="flex justify-end gap-1">
                            <AppButton
                                v-if="row.abilities.refresh"
                                size="sm"
                                variant="ghost"
                                title="Ask the provider again"
                                @click="refresh(row)"
                            >
                                <template #icon
                                    ><RefreshCw class="size-4"
                                /></template>
                                Check
                            </AppButton>
                            <AppButton
                                v-if="row.abilities.retry"
                                size="sm"
                                variant="ghost"
                                title="Start a fresh attempt"
                                @click="retry(row)"
                            >
                                <template #icon
                                    ><RotateCcw class="size-4"
                                /></template>
                                Retry
                            </AppButton>
                        </div>
                    </template>
                </DataTable>
            </AppCard>
        </div>

        <ClientOnly>
            <Modal
                :open="resolving !== null"
                title="What happened to this money?"
                size="sm"
                @update:open="(value) => !value && (resolving = null)"
                @close="resolving = null"
            >
                <div class="space-y-3">
                    <p
                        v-if="resolving"
                        class="rounded-md bg-muted/50 px-3 py-2 text-sm"
                    >
                        <span class="font-medium">{{
                            resolving.member_name ?? 'The group'
                        }}</span>
                        — <MoneyText :ngwee="resolving.amount_ngwee" />
                        <span class="mt-1 block text-gold-700 dark:text-gold-300">{{
                            resolving.status_reason
                        }}</span>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Record it if whatever the ledger objected to has since
                        been dealt with. Set it aside if the money was refunded
                        or handled outside the system — either way the payment
                        stays on the record.
                    </p>
                    <TextareaInput
                        v-model="resolveNote"
                        placeholder="What was done about it"
                        :rows="2"
                    />
                </div>

                <template #footer>
                    <AppButton variant="ghost" @click="resolving = null"
                        >Close</AppButton
                    >
                    <AppButton
                        variant="secondary"
                        @click="resolve('set-aside')"
                    >
                        Set aside
                    </AppButton>
                    <AppButton @click="resolve('post')">Record it now</AppButton>
                </template>
            </Modal>
        </ClientOnly>

    </AdminLayout>
</template>
