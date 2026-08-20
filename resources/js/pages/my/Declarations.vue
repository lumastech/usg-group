<script setup lang="ts">
/**
 * The member's monthly declaration: three amounts, and what they add up to.
 *
 * This is the screen most of the group uses, on a phone, three days a month. So the
 * window state leads, the total is computed live and shown in red when the member is
 * taking money off the table, and every refusal is the server's own words rather than
 * a rule guessed at here.
 */
import { useForm } from '@inertiajs/vue3';
import {
    CalendarClock,
    HandCoins,
    Lock,
    PiggyBank,
    Receipt,
} from '@lucide/vue';
import { computed } from 'vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    FormField,
    MoneyInput,
    StatusBadge,
    WindowCountdown,
} from '@/components/unity';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    Declaration,
    DeclarationDefaults,
    DeclarationEligibility,
    DeclarationMonth,
    DeclarationRules,
} from '@/types/declarations';

const props = defineProps<{
    member: { id: number; full_name: string; member_number: number } | null;
    month: DeclarationMonth | null;
    declaration: Declaration | null;
    defaults: DeclarationDefaults | null;
    rules: DeclarationRules | null;
    eligibility: DeclarationEligibility | null;
    history: Declaration[];
    abilities: { submit: boolean };
}>();

const form = useForm({
    cycle_month_id: props.month?.id ?? null,
    saving_amount_ngwee: props.defaults?.saving_amount_ngwee ?? null,
    loan_repayment_amount_ngwee:
        props.defaults?.loan_repayment_amount_ngwee ?? null,
    loan_requested_amount_ngwee:
        props.defaults?.loan_requested_amount_ngwee ?? null,
});

/** Savings + repayment − loan requested, exactly as the server derives it. */
const totalExpected = computed<number>(
    () =>
        (form.saving_amount_ngwee ?? 0) +
        (form.loan_repayment_amount_ngwee ?? 0) -
        (form.loan_requested_amount_ngwee ?? 0),
);

const isOpen = computed<boolean>(
    () => props.month?.declarations_open === true && props.abilities.submit,
);

/** The declaration is editable only while the window is open and it is unlocked. */
const canEdit = computed<boolean>(
    () =>
        isOpen.value &&
        (props.declaration === null || props.declaration.abilities.update),
);

const savingsCap = computed<number | undefined>(
    () => props.rules?.savings_cap_ngwee ?? undefined,
);

const requestCeiling = computed<number>(
    () => props.eligibility?.ceiling_ngwee ?? 0,
);

/** Reasons the member cannot borrow at all, shown before they type an amount. */
const blockingReasons = computed<string[]>(() =>
    (props.eligibility?.reasons ?? [])
        .filter((reason) => reason.code !== 'exceeds_savings_multiple')
        .map((reason) => reason.message),
);

function submit(): void {
    form.post('/my/declarations', { preserveScroll: true });
}
</script>

<template>
    <MemberLayout
        title="My declaration"
        heading="My declaration"
        :description="month?.label"
    >
        <AppCard v-if="!member || !month">
            <EmptyState
                title="Nothing to declare yet"
                description="Your login is not linked to a member in a running cycle. Ask the treasurer to link it."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <WindowCountdown
                :window="month.window"
                :seconds-remaining="month.seconds_remaining"
            />

            <!-- Outside the window the form is replaced entirely rather than
                 disabled, so there is nothing to fill in and be refused. -->
            <AppCard v-if="!isOpen">
                <div class="flex items-start gap-4">
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-full bg-muted text-muted-foreground"
                    >
                        <Lock class="size-5" />
                    </span>

                    <div class="min-w-0 space-y-1">
                        <p class="text-sm font-semibold text-card-foreground">
                            Declarations for {{ month.label }} are closed
                        </p>
                        <p class="text-sm text-muted-foreground">
                            The window runs from
                            {{ month.declarations_open_at.slice(0, 10) }} at
                            08:00 to the end of
                            {{ month.declarations_close_at.slice(0, 10) }}.
                            Trading concludes {{ month.trading_concludes_on }}.
                        </p>
                        <p
                            v-if="declaration"
                            class="pt-1 text-sm text-muted-foreground"
                        >
                            You declared
                            {{ formatMoney(declaration.saving_amount_ngwee) }}
                            of savings.
                        </p>
                        <p v-else class="pt-1 text-sm text-muted-foreground">
                            You did not declare this month. Ask the treasurer to
                            capture a late declaration.
                        </p>
                    </div>
                </div>
            </AppCard>

            <AppCard
                v-else
                title="What I will bring this month"
                :description="`Savings and any repayment, less anything I am borrowing. Due at the table on ${month.trading_concludes_on}.`"
            >
                <form class="space-y-5" @submit.prevent="submit">
                    <FormField
                        label="Monthly savings"
                        :error="form.errors.saving_amount_ngwee"
                        :hint="
                            rules?.is_lockdown
                                ? `Capped at ${formatMoney(rules.lockdown_cap_ngwee)} a month from the lockdown onwards.`
                                : `In steps of ${formatMoney(rules?.increment_ngwee ?? 50000)}, at least ${formatMoney(rules?.minimum_ngwee ?? 50000)}.`
                        "
                        required
                    >
                        <MoneyInput
                            v-model="form.saving_amount_ngwee"
                            :step="rules?.increment_ngwee ?? 50000"
                            :min="rules?.minimum_ngwee"
                            :max="savingsCap"
                            :invalid="!!form.errors.saving_amount_ngwee"
                            :disabled="!canEdit"
                        />
                    </FormField>

                    <FormField
                        label="Loan repayment"
                        :error="form.errors.loan_repayment_amount_ngwee"
                        :hint="
                            (defaults?.loan_repayment_amount_ngwee ?? 0) > 0
                                ? `Your schedule has ${formatMoney(defaults!.loan_repayment_amount_ngwee)} due this month.`
                                : 'You have no installment due this month.'
                        "
                    >
                        <MoneyInput
                            v-model="form.loan_repayment_amount_ngwee"
                            :invalid="!!form.errors.loan_repayment_amount_ngwee"
                            :disabled="!canEdit"
                        />
                    </FormField>

                    <FormField
                        label="New loan requested"
                        :error="form.errors.loan_requested_amount_ngwee"
                        :hint="
                            eligibility?.lockdown
                                ? undefined
                                : `You may borrow up to ${formatMoney(requestCeiling)} against your savings.`
                        "
                    >
                        <MoneyInput
                            v-model="form.loan_requested_amount_ngwee"
                            :max="requestCeiling"
                            :invalid="!!form.errors.loan_requested_amount_ngwee"
                            :disabled="!canEdit || eligibility?.lockdown"
                        />

                        <ul
                            v-if="blockingReasons.length"
                            class="mt-2 space-y-1 text-xs text-muted-foreground"
                        >
                            <li
                                v-for="reason in blockingReasons"
                                :key="reason"
                                class="flex gap-2"
                            >
                                <span aria-hidden="true">·</span>
                                <span>{{ reason }}</span>
                            </li>
                        </ul>
                    </FormField>

                    <!-- The figure the member is actually being asked for. A negative
                         total means the fund pays them out on the day. -->
                    <div
                        class="flex items-center justify-between gap-3 rounded-xl border border-border bg-muted px-4 py-3"
                    >
                        <span class="text-sm font-medium">
                            Total expected payment
                        </span>
                        <span
                            class="tabular text-lg font-semibold"
                            :class="
                                totalExpected < 0
                                    ? 'text-destructive'
                                    : 'text-card-foreground'
                            "
                        >
                            {{ formatMoney(totalExpected) }}
                        </span>
                    </div>

                    <p
                        v-if="totalExpected < 0"
                        class="text-xs text-muted-foreground"
                    >
                        You are asking for more than you are bringing, so the
                        fund pays you
                        {{ formatMoney(Math.abs(totalExpected)) }} at the table.
                    </p>

                    <AppButton
                        type="submit"
                        block
                        :loading="form.processing"
                        :disabled="!canEdit"
                    >
                        {{ declaration ? 'Update my declaration' : 'Declare' }}
                    </AppButton>
                </form>
            </AppCard>

            <div class="grid gap-4 sm:grid-cols-3">
                <div
                    class="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3"
                >
                    <PiggyBank class="size-5 text-brand-600" />
                    <span class="text-xs text-muted-foreground">
                        Savings due
                        <span class="tabular block text-sm font-semibold">{{
                            formatMoney(form.saving_amount_ngwee ?? 0)
                        }}</span>
                    </span>
                </div>
                <div
                    class="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3"
                >
                    <Receipt class="size-5 text-brand-600" />
                    <span class="text-xs text-muted-foreground">
                        Repayment
                        <span class="tabular block text-sm font-semibold">{{
                            formatMoney(form.loan_repayment_amount_ngwee ?? 0)
                        }}</span>
                    </span>
                </div>
                <div
                    class="flex items-center gap-3 rounded-xl border border-border bg-card px-4 py-3"
                >
                    <HandCoins class="size-5 text-gold-600" />
                    <span class="text-xs text-muted-foreground">
                        Borrowing
                        <span class="tabular block text-sm font-semibold">{{
                            formatMoney(form.loan_requested_amount_ngwee ?? 0)
                        }}</span>
                    </span>
                </div>
            </div>

            <AppCard title="My past declarations" flush>
                <div v-if="history.length === 0" class="p-5">
                    <EmptyState
                        title="Nothing declared yet"
                        description="Your declarations appear here once you have made one."
                    />
                </div>

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="row in history"
                        :key="row.id"
                        class="flex items-center justify-between gap-3 px-5 py-3"
                    >
                        <span class="min-w-0">
                            <span
                                class="block text-sm font-medium text-card-foreground"
                            >
                                {{ row.month_label }}
                            </span>
                            <span class="block text-xs text-muted-foreground"
                                >{{ formatMoney(row.saving_amount_ngwee) }}
                                savings ·
                                {{
                                    formatMoney(row.loan_repayment_amount_ngwee)
                                }}
                                repaid</span
                            >
                        </span>

                        <span class="flex shrink-0 items-center gap-2">
                            <StatusBadge
                                v-if="row.is_late"
                                status="late"
                                label="Late"
                                size="sm"
                            />
                            <StatusBadge
                                :status="row.status"
                                :label="row.status_label"
                                size="sm"
                            />
                            <span
                                class="tabular text-sm font-semibold"
                                :class="
                                    row.total_expected_payment_ngwee < 0
                                        ? 'text-destructive'
                                        : ''
                                "
                            >
                                {{
                                    formatMoney(
                                        row.total_expected_payment_ngwee,
                                    )
                                }}
                            </span>
                        </span>
                    </li>
                </ul>
            </AppCard>

            <p class="flex items-center gap-2 text-xs text-muted-foreground">
                <CalendarClock class="size-4 shrink-0" />
                Declarations open at 08:00 on the 1st and close at the end of
                the 3rd. Trading runs from the 4th.
            </p>
        </div>
    </MemberLayout>
</template>
