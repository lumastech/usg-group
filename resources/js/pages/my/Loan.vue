<script setup lang="ts">
/**
 * A member's own loan, on the phone they carry.
 *
 * The request form appears only when they actually qualify. When they do not, they are
 * shown the same reasons the committee sees — one per failed condition, in plain words
 * — rather than a disabled button with nothing behind it.
 */
import { useForm } from '@inertiajs/vue3';
import {
    CalendarClock,
    CircleCheck,
    CircleX,
    Coins,
    TriangleAlert,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    FormField,
    MoneyInput,
    StatCard,
    StatusBadge,
} from '@/components/unity';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    Loan,
    LoanEligibility,
    LoanRules,
    LoanScheduleItem,
    LoanTransaction,
} from '@/types/loans';

const props = defineProps<{
    member: { id: number; full_name: string; member_number: number } | null;
    loan: Loan | null;
    schedule: LoanScheduleItem[];
    ledger: LoanTransaction[];
    history: { data: Loan[] };
    eligibility: LoanEligibility | null;
    rules: LoanRules | null;
}>();

const requesting = ref(false);

const form = useForm({
    member_id: props.member?.id ?? null,
    principal_ngwee: null as number | null,
});

const penalties = computed(() =>
    props.ledger.filter(
        (entry) =>
            entry.penalty_portion_ngwee > 0 || entry.type.includes('penalty'),
    ),
);

function submit(): void {
    form.post('/my/loan', {
        preserveScroll: true,
        onSuccess: () => {
            requesting.value = false;
            form.reset('principal_ngwee');
        },
    });
}
</script>

<template>
    <MemberLayout title="My loan" heading="My loan">
        <AppCard v-if="!member">
            <EmptyState
                title="No member record"
                description="Your login is not linked to a member in this cycle yet. Ask the committee to link it."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <!-- The loan they are actually on, if any. -->
            <template v-if="loan">
                <AppCard>
                    <template #header>
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-semibold">
                                {{ formatMoney(loan.principal_ngwee) }} over
                                {{ loan.tenor_months }} months
                            </h2>
                            <StatusBadge
                                :status="loan.status"
                                :label="loan.status_label"
                                size="sm"
                            />
                        </div>
                    </template>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <StatCard
                            label="Still owed"
                            :ngwee="loan.balance_ngwee"
                            :icon="Coins"
                            accent="brand"
                            compact
                        />
                        <StatCard
                            label="Next payment"
                            :ngwee="loan.next_due_ngwee ?? 0"
                            :icon="CalendarClock"
                            :hint="loan.next_due_on ?? 'Nothing outstanding'"
                            compact
                        />
                        <StatCard
                            label="Penalties"
                            :ngwee="loan.penalties_ngwee"
                            :icon="TriangleAlert"
                            :hint="
                                loan.days_late > 0
                                    ? `${loan.days_late} days late`
                                    : 'On time'
                            "
                            compact
                        />
                    </div>
                </AppCard>

                <AppCard title="Your schedule" flush>
                    <ul class="divide-y divide-border">
                        <li
                            v-for="item in schedule"
                            :key="item.id"
                            class="flex items-center justify-between gap-3 px-4 py-3"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    {{ item.month_label }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Due {{ item.due_on }}
                                </p>
                            </div>

                            <div class="text-right">
                                <p class="tabular text-sm font-semibold">
                                    {{ formatMoney(item.amount_due_ngwee) }}
                                </p>
                                <StatusBadge
                                    :status="item.status"
                                    :label="item.status_label"
                                    size="sm"
                                />
                            </div>
                        </li>
                    </ul>
                </AppCard>

                <AppCard
                    v-if="penalties.length > 0"
                    title="Penalties charged"
                    description="K100 a day for a late payment, and 10% of the balance for a month that closes short."
                    flush
                >
                    <ul class="divide-y divide-border">
                        <li
                            v-for="entry in penalties"
                            :key="entry.id"
                            class="flex items-center justify-between gap-3 px-4 py-3 text-sm"
                        >
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ entry.type_label }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ entry.notes ?? entry.occurred_on }}
                                </p>
                            </div>
                            <span class="tabular font-semibold">
                                {{ formatMoney(entry.amount_ngwee) }}
                            </span>
                        </li>
                    </ul>
                </AppCard>
            </template>

            <!-- No loan running: either invite them to ask, or explain why not. -->
            <template v-else>
                <AppCard
                    v-if="eligibility?.eligible"
                    title="You can borrow"
                    :description="`Up to ${formatMoney(rules?.ceiling_ngwee ?? 0)} — ${rules?.max_loan_multiple}× what you have saved.`"
                >
                    <div
                        class="mb-4 flex items-start gap-2 rounded-lg bg-brand-50 p-3 text-sm text-brand-800 ring-1 ring-brand-200 dark:bg-brand-400/15 dark:text-brand-100 dark:ring-brand-400/25"
                    >
                        <CircleCheck class="mt-0.5 size-4 shrink-0" />
                        <span>
                            Interest is
                            {{ (rules?.monthly_interest_bps ?? 0) / 100 }}% a
                            month on what is still owed, and everything must be
                            repaid by {{ rules?.final_repayment_date }}.
                        </span>
                    </div>

                    <template v-if="requesting">
                        <FormField
                            label="How much?"
                            :error="form.errors.principal_ngwee"
                            required
                        >
                            <MoneyInput
                                v-model="form.principal_ngwee"
                                :min="rules?.minimum_ngwee"
                                :max="rules?.ceiling_ngwee"
                            />
                        </FormField>

                        <div class="mt-4 flex gap-2">
                            <AppButton
                                variant="ghost"
                                block
                                @click="requesting = false"
                            >
                                Cancel
                            </AppButton>
                            <AppButton
                                block
                                :loading="form.processing"
                                @click="submit"
                            >
                                Send request
                            </AppButton>
                        </div>
                    </template>

                    <AppButton
                        v-else
                        block
                        size="lg"
                        @click="requesting = true"
                    >
                        <template #icon><Wallet class="size-5" /></template>
                        Request a loan
                    </AppButton>
                </AppCard>

                <AppCard
                    v-else-if="eligibility"
                    title="You cannot borrow right now"
                    description="Here is exactly what is standing in the way."
                >
                    <ul class="space-y-2">
                        <li
                            v-for="reason in eligibility.reasons"
                            :key="reason.code"
                            class="flex items-start gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/25"
                        >
                            <CircleX class="mt-0.5 size-4 shrink-0" />
                            <span>{{ reason.message }}</span>
                        </li>
                    </ul>
                </AppCard>
            </template>

            <AppCard
                v-if="history.data.length > 0"
                title="Your loan history"
                flush
            >
                <ul class="divide-y divide-border">
                    <li
                        v-for="past in history.data"
                        :key="past.id"
                        class="flex items-center justify-between gap-3 px-4 py-3 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="tabular font-medium">
                                {{ formatMoney(past.principal_ngwee) }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ past.requested_at?.slice(0, 10) }}
                            </p>
                        </div>
                        <StatusBadge
                            :status="past.status"
                            :label="past.status_label"
                            size="sm"
                        />
                    </li>
                </ul>
            </AppCard>
        </div>
    </MemberLayout>
</template>
