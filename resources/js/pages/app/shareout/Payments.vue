<script setup lang="ts">
/**
 * Paying the share-out schedule out through the gateway.
 *
 * The settling already happened on the batch screen: every position was signed and
 * frozen, and each row here is a decision the group has already taken. This is only the
 * money leaving.
 *
 * The one thing this screen will not do is start a run it cannot finish. The group's
 * balance at the provider is checked against the whole schedule before the first
 * transfer, because half a room paid and no record of who is next is the worst place
 * this system could leave anybody.
 */
import { router } from '@inertiajs/vue3';
import { CircleAlert, HandCoins, Send, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    ConfirmDialog,
    DataTable,
    EmptyState,
    MoneyText,
    StatCard,
} from '@/components/unity';
import type { Column } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type {
    ShareOutPaymentPreview,
    ShareOutPaymentRow,
} from '@/types/payments';

const props = defineProps<{
    cycle: {
        id: number;
        name: string;
        status: string;
        status_label: string;
    } | null;
    preview: ShareOutPaymentPreview | null;
}>();

const confirming = ref(false);
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const columns: Column<ShareOutPaymentRow>[] = [
    { key: 'member_number', label: '#', width: '4rem' },
    { key: 'full_name', label: 'Member' },
    { key: 'destination', label: 'Sent to' },
    { key: 'amount_ngwee', label: 'Amount', numeric: true },
];

const summary = computed(() => props.preview);

function send(payload: {
    approver_email?: string;
    approver_password?: string;
}): void {
    processing.value = true;
    errors.value = {};

    router.post('/app/shareout/payments', payload, {
        preserveScroll: true,
        onError: (bag) => {
            errors.value = bag as Record<string, string>;
        },
        onSuccess: () => {
            confirming.value = false;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Share-out payments"
        heading="Send the share-out"
        description="Every payout that has somewhere to go, sent one at a time."
    >
        <AppCard v-if="!cycle || !summary">
            <EmptyState
                title="No active cycle"
                description="Activate a cycle to send its share-out."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="To send"
                    :ngwee="summary.payable_ngwee"
                    :icon="Send"
                    :hint="`${summary.payable_count} member(s)`"
                    accent="brand"
                />
                <StatCard
                    label="To pay by hand"
                    :ngwee="summary.by_hand_ngwee"
                    :icon="HandCoins"
                    :hint="`${summary.by_hand_count} with no account on file`"
                />
                <StatCard
                    label="At the provider"
                    :ngwee="summary.balance_ngwee ?? 0"
                    :icon="Wallet"
                    :hint="
                        summary.balance_ngwee === null
                            ? 'Could not be read'
                            : 'Available to send'
                    "
                />
                <StatCard
                    label="Short by"
                    :ngwee="summary.shortfall_ngwee"
                    :icon="CircleAlert"
                    :accent="summary.shortfall_ngwee > 0 ? 'gold' : 'none'"
                    hint="Top the account up before running"
                />
            </div>

            <AppCard
                v-if="summary.shortfall_ngwee > 0"
                class="border-l-4 border-l-gold-500"
            >
                <p class="text-sm">
                    The group's account is
                    <MoneyText :ngwee="summary.shortfall_ngwee" /> short of the
                    schedule. Collections have to settle before they can be sent
                    out again — top the account up, or pay the rest by hand.
                </p>
            </AppCard>

            <AppCard flush>
                <DataTable
                    :rows="summary.rows"
                    :columns="columns"
                    row-key="payout_id"
                    empty-title="Nothing to send"
                    empty-description="Every payout has been paid already."
                >
                    <template #cell-full_name="{ row }">
                        <p class="font-medium">{{ row.full_name }}</p>
                        <p
                            v-if="row.account_name"
                            class="text-xs"
                            :class="
                                row.needs_confirmation
                                    ? 'text-gold-700 dark:text-gold-300'
                                    : 'text-muted-foreground'
                            "
                        >
                            In the name of {{ row.account_name }}
                        </p>
                    </template>

                    <template #cell-destination="{ row }">
                        <span v-if="row.by_hand" class="text-muted-foreground">
                            No account on file — pay by hand
                        </span>
                        <span v-else>{{ row.destination }}</span>
                    </template>

                    <template #cell-amount_ngwee="{ row }">
                        <MoneyText :ngwee="row.amount_ngwee" />
                    </template>
                </DataTable>
            </AppCard>

            <AppCard
                title="Send the schedule"
                description="Two committee members sign for the run, exactly as they signed for the settlements."
            >
                <AppButton
                    variant="gold"
                    :disabled="!summary.can_run"
                    @click="confirming = true"
                >
                    Send
                    <MoneyText :ngwee="summary.payable_ngwee" />
                    to
                    {{ summary.payable_count }} member(s)
                </AppButton>
            </AppCard>
        </div>

        <ClientOnly>
            <ConfirmDialog
                v-model:open="confirming"
                variant="dual-approval"
                title="Send the share-out"
                :action-summary="
                    summary
                        ? `${summary.payable_count} transfer(s) totalling K${(summary.payable_ngwee / 100).toLocaleString('en-GB', { minimumFractionDigits: 2 })}`
                        : ''
                "
                confirm-label="Send now"
                :errors="errors"
                :processing="processing"
                @confirm="send"
            />
        </ClientOnly>
    </AdminLayout>
</template>
