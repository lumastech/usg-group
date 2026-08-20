<script setup lang="ts">
/**
 * One loan, end to end: the headline figures, the schedule as issued beside the
 * schedule as it stands now, the full ledger, and the actions the server says this
 * user may take.
 *
 * Nothing on this page decides what is allowed. The action bar renders from
 * `loan.abilities`, which are the policy's own answers, and every write is re-checked
 * server-side when it lands.
 */
import { useForm } from '@inertiajs/vue3';
import {
    Banknote,
    CalendarClock,
    Coins,
    Gavel,
    ShieldCheck,
    TriangleAlert,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    ConfirmDialog,
    EmptyState,
    FormField,
    Modal,
    MoneyInput,
    StatCard,
    StatusBadge,
    TextareaInput,
    TextInput,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    CollateralClaim,
    Loan,
    LoanScheduleItem,
    LoanTransaction,
} from '@/types/loans';

const props = defineProps<{
    loan: Loan;
    schedule: LoanScheduleItem[];
    ledger: LoanTransaction[];
    claim: CollateralClaim | null;
    queuePosition: number | null;
}>();

const approveOpen = ref(false);
const repaymentOpen = ref(false);
const defaultOpen = ref(false);
const claimOpen = ref(false);
const signOffOpen = ref(false);

const approveForm = useForm({ approver_email: '', approver_password: '' });
const repaymentForm = useForm({
    amount_ngwee: null as number | null,
    received_on: new Date().toISOString().slice(0, 10),
});
const defaultForm = useForm({ reason: '' });
const claimForm = useForm({
    items: [{ description: '', estimated_value_ngwee: null as number | null }],
});
const signOffForm = useForm({ approver_email: '', approver_password: '' });
const enforceForm = useForm({});

const nextDue = computed(
    () => props.schedule.find((item) => item.outstanding_ngwee > 0) ?? null,
);

/** The schedule only moved if interest has been repriced against a real balance. */
const scheduleDrifted = computed(() =>
    props.schedule.some(
        (item) => item.amount_due_ngwee !== item.original_amount_due_ngwee,
    ),
);

const claimedTotal = computed(() =>
    claimForm.items.reduce(
        (sum, item) => sum + (item.estimated_value_ngwee ?? 0),
        0,
    ),
);

function approve(payload: {
    approver_email?: string;
    approver_password?: string;
}): void {
    approveForm.approver_email = payload.approver_email ?? '';
    approveForm.approver_password = payload.approver_password ?? '';

    approveForm.post(`/app/loans/${props.loan.id}/approve`, {
        preserveScroll: true,
        onSuccess: () => {
            approveOpen.value = false;
            approveForm.reset();
        },
    });
}

function signOff(payload: {
    approver_email?: string;
    approver_password?: string;
}): void {
    signOffForm.approver_email = payload.approver_email ?? '';
    signOffForm.approver_password = payload.approver_password ?? '';

    signOffForm.post(`/app/collateral/${props.claim?.id}/sign-off`, {
        preserveScroll: true,
        onSuccess: () => {
            signOffOpen.value = false;
            signOffForm.reset();
        },
    });
}

function enforce(): void {
    enforceForm.post(`/app/collateral/${props.claim?.id}/enforce`, {
        preserveScroll: true,
    });
}

function recordRepayment(): void {
    repaymentForm.post(`/app/loans/${props.loan.id}/repayments`, {
        preserveScroll: true,
        onSuccess: () => {
            repaymentOpen.value = false;
            repaymentForm.reset('amount_ngwee');
        },
    });
}

function markDefault(): void {
    defaultForm.post(`/app/loans/${props.loan.id}/default`, {
        preserveScroll: true,
        onSuccess: () => {
            defaultOpen.value = false;
            defaultForm.reset();
        },
    });
}

function submitClaim(): void {
    claimForm.post(`/app/loans/${props.loan.id}/collateral`, {
        preserveScroll: true,
        onSuccess: () => {
            claimOpen.value = false;
            claimForm.reset();
        },
    });
}
</script>

<template>
    <AdminLayout
        :title="`Loan #${loan.id}`"
        :heading="loan.member_name ?? `Loan #${loan.id}`"
        :description="`${formatMoney(loan.principal_ngwee)} over ${loan.tenor_months} months`"
    >
        <template #actions>
            <StatusBadge :status="loan.status" :label="loan.status_label" />

            <AppButton
                v-if="loan.abilities.approve"
                size="sm"
                @click="approveOpen = true"
            >
                <template #icon><ShieldCheck class="size-4" /></template>
                Approve
            </AppButton>

            <AppButton
                v-if="loan.abilities.recordRepayment"
                size="sm"
                variant="secondary"
                @click="repaymentOpen = true"
            >
                <template #icon><Banknote class="size-4" /></template>
                Record repayment
            </AppButton>

            <AppButton
                v-if="loan.abilities.markDefault"
                size="sm"
                variant="destructive"
                @click="defaultOpen = true"
            >
                <template #icon><TriangleAlert class="size-4" /></template>
                Mark in default
            </AppButton>

            <AppButton
                v-if="loan.abilities.claimCollateral && !claim"
                size="sm"
                variant="destructive"
                @click="claimOpen = true"
            >
                <template #icon><Gavel class="size-4" /></template>
                Raise collateral claim
            </AppButton>
        </template>

        <div class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Principal"
                    :ngwee="loan.principal_ngwee"
                    :icon="Wallet"
                    :hint="
                        loan.schedule_compressed
                            ? 'Schedule compressed to meet the deadline'
                            : `${loan.tenor_months} month term`
                    "
                />
                <StatCard
                    label="Balance"
                    :ngwee="loan.balance_ngwee"
                    :icon="Coins"
                    accent="brand"
                />
                <StatCard
                    label="Next due"
                    :ngwee="loan.next_due_ngwee ?? 0"
                    :icon="CalendarClock"
                    :hint="loan.next_due_on ?? 'Nothing outstanding'"
                    :accent="loan.days_late > 0 ? 'gold' : 'none'"
                />
                <StatCard
                    label="Penalties to date"
                    :ngwee="loan.penalties_ngwee"
                    :icon="TriangleAlert"
                    :hint="
                        loan.days_late > 0
                            ? `${loan.days_late} days late`
                            : 'On time'
                    "
                />
            </div>

            <!-- Anything the committee needs to know at a glance before acting. -->
            <AppCard
                v-if="
                    loan.discretion_override ||
                    queuePosition ||
                    loan.out_of_order_reason
                "
            >
                <ul class="space-y-2 text-sm">
                    <li v-if="queuePosition" class="text-muted-foreground">
                        Position
                        <strong class="text-foreground">{{
                            queuePosition
                        }}</strong>
                        in this month's disbursement queue.
                    </li>
                    <li v-if="loan.discretion_override">
                        <span class="text-muted-foreground"
                            >Discretion override:</span
                        >
                        {{ loan.discretion_note }}
                    </li>
                    <li v-if="loan.out_of_order_reason">
                        <span class="text-muted-foreground"
                            >Disbursed out of order:</span
                        >
                        {{ loan.out_of_order_reason }}
                    </li>
                </ul>
            </AppCard>

            <AppCard
                title="Repayment schedule"
                :description="
                    scheduleDrifted
                        ? 'Interest runs on the reducing balance, so what is expected now differs from the schedule issued at disbursement.'
                        : 'As issued at disbursement.'
                "
                flush
            >
                <div v-if="schedule.length === 0" class="p-5">
                    <EmptyState
                        title="No schedule yet"
                        description="A schedule is written when the loan is disbursed."
                    />
                </div>

                <div v-else class="scrollbar-thin overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="border-b border-border text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3 text-left">Month</th>
                                <th class="px-5 py-3 text-left">Due on</th>
                                <th class="px-5 py-3 text-right">Original</th>
                                <th class="px-5 py-3 text-right">Principal</th>
                                <th class="px-5 py-3 text-right">Interest</th>
                                <th class="px-5 py-3 text-right">Due now</th>
                                <th class="px-5 py-3 text-right">Paid</th>
                                <th class="px-5 py-3 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="item in schedule"
                                :key="item.id"
                                :class="
                                    item.id === nextDue?.id
                                        ? 'bg-brand-50/60 dark:bg-brand-400/10'
                                        : ''
                                "
                            >
                                <td class="px-5 py-3">
                                    {{ item.month_label }}
                                </td>
                                <td class="px-5 py-3">{{ item.due_on }}</td>
                                <td
                                    class="tabular px-5 py-3 text-right text-muted-foreground"
                                >
                                    {{
                                        formatMoney(
                                            item.original_amount_due_ngwee,
                                        )
                                    }}
                                </td>
                                <td class="tabular px-5 py-3 text-right">
                                    {{ formatMoney(item.principal_due_ngwee) }}
                                </td>
                                <td class="tabular px-5 py-3 text-right">
                                    {{ formatMoney(item.interest_due_ngwee) }}
                                </td>
                                <td
                                    class="tabular px-5 py-3 text-right font-medium"
                                >
                                    {{ formatMoney(item.amount_due_ngwee) }}
                                </td>
                                <td class="tabular px-5 py-3 text-right">
                                    {{ formatMoney(item.amount_paid_ngwee) }}
                                </td>
                                <td class="px-5 py-3">
                                    <StatusBadge
                                        :status="item.status"
                                        :label="item.status_label"
                                        size="sm"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <AppCard
                title="Ledger"
                description="Append-only. A charge posted in error is corrected by a reversing entry, never by an edit."
                flush
            >
                <div v-if="ledger.length === 0" class="p-5">
                    <EmptyState title="Nothing posted yet" />
                </div>

                <div v-else class="scrollbar-thin overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead
                            class="border-b border-border text-xs tracking-wide text-muted-foreground uppercase"
                        >
                            <tr>
                                <th class="px-5 py-3 text-left">Date</th>
                                <th class="px-5 py-3 text-left">Entry</th>
                                <th class="px-5 py-3 text-right">Amount</th>
                                <th class="px-5 py-3 text-right">Balance</th>
                                <th class="px-5 py-3 text-left">Note</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="entry in ledger" :key="entry.id">
                                <td class="px-5 py-3 whitespace-nowrap">
                                    {{ entry.occurred_on }}
                                </td>
                                <td class="px-5 py-3">
                                    {{ entry.type_label }}
                                </td>
                                <td
                                    :class="[
                                        'tabular px-5 py-3 text-right font-medium',
                                        entry.signed_amount_ngwee < 0
                                            ? 'text-brand-700 dark:text-brand-300'
                                            : 'text-foreground',
                                    ]"
                                >
                                    {{ formatMoney(entry.signed_amount_ngwee) }}
                                </td>
                                <td class="tabular px-5 py-3 text-right">
                                    {{ formatMoney(entry.balance_after_ngwee) }}
                                </td>
                                <td class="px-5 py-3 text-muted-foreground">
                                    {{ entry.notes ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <!-- The recovery trail, once a default has been declared. -->
            <AppCard
                v-if="claim"
                title="Collateral claim"
                :description="`${formatMoney(claim.claimed_value_ngwee)} of household goods against ${formatMoney(claim.outstanding_at_claim_ngwee)} owed`"
            >
                <template #actions>
                    <StatusBadge
                        :status="claim.status"
                        :label="claim.status_label"
                    />
                    <AppButton
                        v-if="claim.abilities.signOff"
                        size="sm"
                        @click="signOffOpen = true"
                    >
                        Second signature
                    </AppButton>
                    <AppButton
                        v-if="claim.abilities.enforce"
                        size="sm"
                        variant="destructive"
                        :loading="enforceForm.processing"
                        @click="enforce"
                    >
                        Enforce
                    </AppButton>
                </template>

                <ul class="divide-y divide-border text-sm">
                    <li
                        v-for="(item, index) in claim.items"
                        :key="index"
                        class="flex justify-between gap-4 py-2.5"
                    >
                        <span>{{ item.description }}</span>
                        <span class="tabular font-medium">
                            {{ formatMoney(item.estimated_value_ngwee) }}
                        </span>
                    </li>
                </ul>

                <p class="mt-3 text-xs text-muted-foreground">
                    Prepared by {{ claim.prepared_by ?? '—'
                    }}<template v-if="claim.second_signer"
                        >, signed by {{ claim.second_signer }}</template
                    >.
                </p>
            </AppCard>
        </div>

        <ClientOnly>
            <!-- Two-person rule: the second committee member confirms on this device. -->
            <ConfirmDialog
                v-model:open="approveOpen"
                variant="dual-approval"
                title="Approve this loan"
                :action-summary="`Approve ${formatMoney(loan.principal_ngwee)} for ${loan.member_name}`"
                confirm-label="Approve"
                :errors="approveForm.errors"
                :processing="approveForm.processing"
                @confirm="approve"
            />

            <ConfirmDialog
                v-model:open="signOffOpen"
                variant="dual-approval"
                title="Sign off the collateral claim"
                :action-summary="`Confirm the claim against ${loan.member_name}'s household goods`"
                confirm-label="Sign off"
                :errors="signOffForm.errors"
                :processing="signOffForm.processing"
                @confirm="signOff"
            />

            <Modal v-model:open="repaymentOpen" title="Record a repayment">
                <div class="space-y-4">
                    <FormField
                        label="Amount received"
                        :error="repaymentForm.errors.amount_ngwee"
                        required
                    >
                        <MoneyInput v-model="repaymentForm.amount_ngwee" />
                    </FormField>

                    <FormField
                        label="Date received"
                        hint="A payment after the month's trading date carries the daily late penalty."
                        :error="repaymentForm.errors.received_on"
                        required
                    >
                        <TextInput
                            v-model="repaymentForm.received_on"
                            type="date"
                        />
                    </FormField>
                </div>

                <template #footer>
                    <AppButton variant="ghost" @click="repaymentOpen = false">
                        Cancel
                    </AppButton>
                    <AppButton
                        :loading="repaymentForm.processing"
                        @click="recordRepayment"
                    >
                        Record
                    </AppButton>
                </template>
            </Modal>

            <Modal
                v-model:open="defaultOpen"
                title="Declare this loan in default"
            >
                <FormField
                    label="Reason"
                    hint="This goes on the member's record and opens the collateral claim workflow."
                    :error="defaultForm.errors.reason"
                    required
                >
                    <TextareaInput v-model="defaultForm.reason" :rows="3" />
                </FormField>

                <template #footer>
                    <AppButton variant="ghost" @click="defaultOpen = false">
                        Cancel
                    </AppButton>
                    <AppButton
                        variant="destructive"
                        :loading="defaultForm.processing"
                        @click="markDefault"
                    >
                        Declare default
                    </AppButton>
                </template>
            </Modal>

            <Modal
                v-model:open="claimOpen"
                size="lg"
                title="Raise a collateral claim"
                description="Itemise household goods to at least the value still owed, as the guarantee clause requires."
            >
                <div class="space-y-3">
                    <div
                        v-for="(item, index) in claimForm.items"
                        :key="index"
                        class="grid gap-3 sm:grid-cols-[1fr_10rem_auto]"
                    >
                        <TextInput
                            v-model="item.description"
                            placeholder="Deep freezer"
                        />
                        <MoneyInput
                            v-model="item.estimated_value_ngwee"
                            :steppers="false"
                        />
                        <AppButton
                            variant="ghost"
                            size="sm"
                            :disabled="claimForm.items.length === 1"
                            @click="claimForm.items.splice(index, 1)"
                        >
                            Remove
                        </AppButton>
                    </div>

                    <AppButton
                        variant="outline"
                        size="sm"
                        @click="
                            claimForm.items.push({
                                description: '',
                                estimated_value_ngwee: null,
                            })
                        "
                    >
                        Add item
                    </AppButton>

                    <p
                        v-if="claimForm.errors.items"
                        class="text-sm text-destructive"
                    >
                        {{ claimForm.errors.items }}
                    </p>

                    <p class="text-sm text-muted-foreground">
                        Listed:
                        <span class="tabular font-medium text-foreground">
                            {{ formatMoney(claimedTotal) }}
                        </span>
                        against
                        <span class="tabular font-medium text-foreground">
                            {{ formatMoney(loan.balance_ngwee) }}
                        </span>
                        owed.
                    </p>
                </div>

                <template #footer>
                    <AppButton variant="ghost" @click="claimOpen = false">
                        Cancel
                    </AppButton>
                    <AppButton
                        variant="destructive"
                        :loading="claimForm.processing"
                        @click="submitClaim"
                    >
                        Draft claim
                    </AppButton>
                </template>
            </Modal>
        </ClientOnly>
    </AdminLayout>
</template>
