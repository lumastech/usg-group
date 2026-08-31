<script setup lang="ts">
/**
 * The wallet float, as the committee needs to read it.
 *
 * The alarm at the top is the point of this screen. Wallets introduce an internal
 * balance that must always be backed by real cash, and the invariant is the only thing
 * standing between the group and a float that quietly does not exist. It is also the
 * first check in the system that catches a fraud requiring no ledger tampering at all.
 *
 * What members hold is a LIABILITY — money the group owes on demand. It is not group
 * funds and it never appears in the savings pool or the social fund balance.
 */
import { router, useForm } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, HandCoins, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    DataTable,
    EmptyState,
    FormField,
    Modal,
    MoneyInput,
    MoneyText,
    SelectInput,
    StatCard,
    StatusBadge,
    TextInput,
} from '@/components/unity';
import type { Column } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { Wallet as MemberWallet } from '@/types/wallets';

const props = defineProps<{
    wallets: MemberWallet[];
    group: MemberWallet;
    invariants: {
        wallet_total_ngwee: number;
        member_liability_ngwee: number;
        group_wallet_ngwee: number;
        cash_tin_ngwee: number;
        withdrawals_in_flight_ngwee: number;
        provider_balance_ngwee: number | null;
        expected_wallet_total_ngwee: number | null;
        wallet_variance_ngwee: number | null;
        group_wallet_variance_ngwee: number;
        balances: boolean;
        provider_unreachable: boolean;
    };
    abilities: { recordCash: boolean; reconcile: boolean };
}>();

const columns: Column<MemberWallet>[] = [
    { key: 'member_number', label: '#' },
    { key: 'member_name', label: 'Member' },
    { key: 'balance_ngwee', label: 'Balance', numeric: true },
    { key: 'status_label', label: 'Status' },
    { key: 'actions', label: '', align: 'right' },
];

const showCashIn = ref(false);
const showCashOut = ref(false);

const memberOptions = computed(() =>
    props.wallets.map((wallet) => ({
        value: wallet.member_id as number,
        label: wallet.member_name ?? `Member ${wallet.member_number ?? ''}`,
    })),
);

const withBalance = computed(
    () => props.wallets.filter((wallet) => wallet.balance_ngwee > 0).length,
);

const cashIn = useForm({
    member_id: null as number | null,
    amount_ngwee: null as number | null,
    note: '',
});

/*
 * Cash out carries the second signature in the same request. The committee set this
 * stricter than the fund's threshold rule on purpose: a provider transfer leaves a
 * record at the provider, and a banknote leaves only the wallet entry.
 */
const cashOut = useForm({
    member_id: null as number | null,
    amount_ngwee: null as number | null,
    note: '',
    approver_email: '',
    approver_password: '',
});

function submitCashIn(): void {
    cashIn.post('/app/wallets/cash-in', {
        preserveScroll: true,
        onSuccess: () => {
            cashIn.reset();
            showCashIn.value = false;
        },
    });
}

function submitCashOut(): void {
    cashOut.post('/app/wallets/cash-out', {
        preserveScroll: true,
        onSuccess: () => {
            cashOut.reset();
            showCashOut.value = false;
        },
        onFinish: () => cashOut.reset('approver_password'),
    });
}

function open(wallet: MemberWallet): void {
    router.get(`/app/wallets/${wallet.id}`);
}
</script>

<template>
    <AdminLayout
        title="Wallets"
        heading="Wallets"
        description="Money standing between the members and the group, and whether it is really there."
    >
        <div class="space-y-6">
            <!-- Invariant 1. A mismatch is an alarm, not a report. -->
            <div
                v-if="invariants.provider_unreachable"
                class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-950 dark:text-amber-100"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                <p>
                    The payment provider could not be reached, so the float has
                    not been checked. This is not news about the money — but the
                    check has not run.
                </p>
            </div>

            <div
                v-else-if="!invariants.balances"
                class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-950 dark:text-red-100"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                <div>
                    <p class="font-medium">
                        The wallet float is out by
                        <MoneyText
                            :ngwee="invariants.wallet_variance_ngwee ?? 0"
                        />.
                    </p>
                    <p class="mt-1">
                        Wallets hold
                        <MoneyText :ngwee="invariants.wallet_total_ngwee" />,
                        and the money behind them comes to
                        <MoneyText
                            :ngwee="invariants.expected_wallet_total_ngwee ?? 0"
                        />. Somebody has to look at this today.
                    </p>
                </div>
            </div>

            <div
                v-else
                class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-950 dark:text-emerald-100"
            >
                <CircleCheck class="mt-0.5 size-4 shrink-0" />
                <p>
                    Every wallet balance is backed by money the group holds.
                    Checked against the provider balance, the cash tin and
                    <MoneyText
                        :ngwee="invariants.withdrawals_in_flight_ngwee"
                    />
                    still in flight.
                </p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Owed to members"
                    :ngwee="invariants.member_liability_ngwee"
                    hint="A liability, not group funds. Never part of the savings pool or the fund."
                    :icon="Wallet"
                    accent="gold"
                />
                <StatCard
                    label="The group's wallet"
                    :ngwee="invariants.group_wallet_ngwee"
                    hint="What the group holds and has not yet paid out."
                    :icon="HandCoins"
                />
                <StatCard
                    label="In the cash tin"
                    :ngwee="invariants.cash_tin_ngwee"
                    hint="The one part of the float with no provider record behind it."
                />
            </div>

            <AppCard
                title="Member wallets"
                :description="`${withBalance} of ${wallets.length} are holding money.`"
                flush
            >
                <template #actions>
                    <div v-if="abilities.recordCash" class="flex gap-2">
                        <AppButton
                            size="sm"
                            variant="outline"
                            @click="showCashIn = true"
                        >
                            Cash in
                        </AppButton>
                        <AppButton
                            size="sm"
                            variant="outline"
                            @click="showCashOut = true"
                        >
                            Cash out
                        </AppButton>
                    </div>
                </template>

                <EmptyState
                    v-if="wallets.length === 0"
                    title="No wallets yet"
                    description="A wallet is opened with each member."
                />

                <DataTable
                    v-else
                    :rows="wallets"
                    :columns="columns"
                    row-key="id"
                    @row-click="open"
                >
                    <template #cell-balance_ngwee="{ row }">
                        <MoneyText :ngwee="row.balance_ngwee" />
                    </template>

                    <template #cell-status_label="{ row }">
                        <StatusBadge
                            :status="row.status"
                            :label="row.status_label"
                            size="sm"
                        />
                    </template>

                    <template #cell-actions="{ row }">
                        <AppButton
                            size="sm"
                            variant="ghost"
                            @click.stop="open(row)"
                        >
                            Statement
                        </AppButton>
                    </template>
                </DataTable>
            </AppCard>
        </div>

        <!-- Banknotes counted at the table: the same authority a treasurer
             already has when recording a cash contribution today. -->
        <Modal
            v-model:open="showCashIn"
            title="Record cash into a wallet"
            description="Money the member handed over, credited to their own wallet. Nothing is decided about what it is for."
        >
            <div class="space-y-4">
                <FormField label="Member" :error="cashIn.errors.member_id">
                    <template #default="{ id, invalid }">
                        <SelectInput
                            :id="id"
                            v-model="cashIn.member_id"
                            :invalid="invalid"
                            :options="memberOptions"
                        />
                    </template>
                </FormField>

                <FormField label="Amount" :error="cashIn.errors.amount_ngwee">
                    <template #default="{ id, invalid }">
                        <MoneyInput
                            :id="id"
                            v-model="cashIn.amount_ngwee"
                            :invalid="invalid"
                        />
                    </template>
                </FormField>

                <FormField label="Note" :error="cashIn.errors.note">
                    <template #default="{ id, invalid }">
                        <TextInput
                            :id="id"
                            v-model="cashIn.note"
                            :invalid="invalid"
                            placeholder="Counted at the January table"
                        />
                    </template>
                </FormField>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <AppButton variant="ghost" @click="showCashIn = false">
                        Cancel
                    </AppButton>
                    <AppButton
                        :loading="cashIn.processing"
                        :disabled="!cashIn.member_id || !cashIn.amount_ngwee"
                        @click="submitCashIn"
                    >
                        Record it
                    </AppButton>
                </div>
            </template>
        </Modal>

        <!-- Cash out needs two signatures whatever the amount. The second
             committee member types their own credentials on this device. -->
        <Modal
            v-model:open="showCashOut"
            title="Pay a wallet out in cash"
            description="Two signatures, whatever the amount — a banknote leaves no record anywhere but this entry."
        >
            <div class="space-y-4">
                <FormField label="Member" :error="cashOut.errors.member_id">
                    <template #default="{ id, invalid }">
                        <SelectInput
                            :id="id"
                            v-model="cashOut.member_id"
                            :invalid="invalid"
                            :options="memberOptions"
                        />
                    </template>
                </FormField>

                <FormField label="Amount" :error="cashOut.errors.amount_ngwee">
                    <template #default="{ id, invalid }">
                        <MoneyInput
                            :id="id"
                            v-model="cashOut.amount_ngwee"
                            :invalid="invalid"
                        />
                    </template>
                </FormField>

                <div
                    class="space-y-4 rounded-xl border border-border bg-muted/40 p-4"
                >
                    <p class="text-xs text-muted-foreground">
                        A second committee member confirms with their own portal
                        login. It is never the same person twice.
                    </p>

                    <FormField
                        label="Confirming member's email"
                        :error="cashOut.errors.approver_email"
                    >
                        <template #default="{ id, invalid }">
                            <TextInput
                                :id="id"
                                v-model="cashOut.approver_email"
                                :invalid="invalid"
                                type="email"
                                autocomplete="off"
                            />
                        </template>
                    </FormField>

                    <FormField
                        label="Their password"
                        :error="cashOut.errors.approver_password"
                    >
                        <template #default="{ id, invalid }">
                            <TextInput
                                :id="id"
                                v-model="cashOut.approver_password"
                                :invalid="invalid"
                                type="password"
                                autocomplete="new-password"
                            />
                        </template>
                    </FormField>
                </div>
            </div>

            <template #footer>
                <div class="flex justify-end gap-2">
                    <AppButton variant="ghost" @click="showCashOut = false">
                        Cancel
                    </AppButton>
                    <AppButton
                        variant="destructive"
                        :loading="cashOut.processing"
                        :disabled="
                            !cashOut.member_id ||
                            !cashOut.amount_ngwee ||
                            !cashOut.approver_email ||
                            !cashOut.approver_password
                        "
                        @click="submitCashOut"
                    >
                        Pay it out
                    </AppButton>
                </div>
            </template>
        </Modal>
    </AdminLayout>
</template>
