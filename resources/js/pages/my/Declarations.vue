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
    CircleCheck,
    CreditCard,
    HandCoins,
    Lock,
    PiggyBank,
    Receipt,
    Smartphone,
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
import { usePaymentWidget } from '@/composables/usePaymentWidget';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    Declaration,
    DeclarationDefaults,
    DeclarationEligibility,
    DeclarationMonth,
    DeclarationRules,
} from '@/types/declarations';
import type { PaymentIntent } from '@/types/payments';

const props = defineProps<{
    member: { id: number; full_name: string; member_number: number } | null;
    month: DeclarationMonth | null;
    declaration: Declaration | null;
    defaults: DeclarationDefaults | null;
    rules: DeclarationRules | null;
    eligibility: DeclarationEligibility | null;
    history: Declaration[];
    /** The payment standing against this month's declaration, if one was started. */
    payment: PaymentIntent | null;
    abilities: { submit: boolean; pay: boolean };
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

/**
 * Approved and waiting to be paid: the committee has asked for these figures, so they
 * are no longer the member's to change and the money can now be sent.
 */
const pendingPayment = computed<boolean>(
    () =>
        props.declaration?.approved === true &&
        props.declaration.status !== 'processed',
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

/** What the prompt asks for: savings plus repayment, to the ngwee, and nothing else. */
const payable = computed<number>(
    () => props.declaration?.expected_in_ngwee ?? 0,
);

/** A loan is paid out separately, so it is never netted off what the member is asked. */
const borrowing = computed<number>(
    () => props.declaration?.loan_requested_amount_ngwee ?? 0,
);

const waitingOnPhone = computed<boolean>(() =>
    ['draft', 'pending', 'awaiting-authorization'].includes(
        props.payment?.status ?? '',
    ),
);

/**
 * The prompt went out and nobody ever answered it.
 *
 * An unapproved handset prompt never comes back as a refusal, it simply goes quiet, so
 * past the give-up window the server stops treating it as a payment in flight. Saying
 * so here is what gives the member a way out: the alternative is a screen that asks
 * them forever to approve something their phone no longer has.
 */
const stalled = computed<boolean>(() => props.payment?.has_stalled === true);

const paid = computed<boolean>(() =>
    ['successful', 'settled', 'posted'].includes(props.payment?.status ?? ''),
);

const payForm = useForm({ channel: 'mobile_money' });

/* The provider's hosted page, when the member would rather pay by card. Null when no
   gateway is configured, which is what the card button checks. */
const { widget, openIfStarted, verify } = usePaymentWidget();

/** Which of the two buttons is waiting on the server, so only that one spins. */
const paying = computed<'mobile_money' | 'card' | null>(() =>
    payForm.processing ? (payForm.channel as 'mobile_money' | 'card') : null,
);

/**
 * Pays the approved amount, on whichever rail the member picked.
 *
 * There is nothing to type either way: the amount is the one the committee approved.
 * A prompt is answered on the handset; a card hands the member to the provider's own
 * page, and the money is only believed once the provider is asked.
 */
function pay(channel: 'mobile_money' | 'card'): void {
    payForm.channel = channel;

    payForm.post('/my/declarations/pay', {
        preserveScroll: true,
        onSuccess: () => {
            openIfStarted();
        },
    });
}

/** Asks the provider what became of the payment; the browser is never believed. */
function checkPayment(): void {
    if (props.payment) {
        verify(props.payment.id);
    }
}

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

            <!-- Approved: the form is gone, because there is nothing left to decide.
                 What is left is paying it, which either the member or the treasury
                 may start. -->
            <AppCard v-if="pendingPayment && declaration">
                <div class="flex items-start gap-4">
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-50 text-brand-700 dark:bg-brand-400/15 dark:text-brand-200"
                    >
                        <CircleCheck class="size-5" />
                    </span>

                    <div class="min-w-0 flex-1 space-y-3">
                        <div class="space-y-1">
                            <p
                                class="text-sm font-semibold text-card-foreground"
                            >
                                Your {{ month.label }} declaration is approved
                            </p>
                            <p class="text-sm text-muted-foreground">
                                The committee has accepted your figures, so they
                                can no longer be changed. Ask the treasurer to
                                reopen it if something is wrong.
                            </p>
                        </div>

                        <div
                            class="flex items-center justify-between gap-3 rounded-xl border border-border bg-muted px-4 py-3"
                        >
                            <span class="text-sm font-medium"
                                >Due at the table</span
                            >
                            <span
                                class="tabular text-lg font-semibold"
                                :class="
                                    declaration.total_expected_payment_ngwee < 0
                                        ? 'text-destructive'
                                        : 'text-card-foreground'
                                "
                            >
                                {{
                                    formatMoney(
                                        declaration.total_expected_payment_ngwee,
                                    )
                                }}
                            </span>
                        </div>

                        <!-- The loan is a separate movement: the member brings their
                             savings and repayment, and the fund pays the loan out to
                             them when it is disbursed. -->
                        <p
                            v-if="borrowing > 0"
                            class="text-xs text-muted-foreground"
                        >
                            You pay {{ formatMoney(payable) }} now. The
                            {{ formatMoney(borrowing) }} you asked to borrow is
                            paid out to you separately once the loan is
                            disbursed.
                        </p>

                        <div
                            v-if="paid"
                            class="rounded-xl border border-border bg-muted px-4 py-3"
                        >
                            <p class="text-sm font-medium text-card-foreground">
                                {{ payment?.member_status_label }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ formatMoney(payable) }} received for
                                {{ month.label }}.
                            </p>
                        </div>

                        <template v-else>
                            <!-- One prompt, for the whole of what was approved.
                                 A live one is shown instead of a second button. -->
                            <div
                                v-if="payment && !stalled"
                                class="rounded-xl border border-border bg-muted px-4 py-3"
                            >
                                <p
                                    class="text-sm font-medium text-card-foreground"
                                >
                                    {{ payment.member_status_label }}
                                </p>
                                <p
                                    v-if="payment.status_reason"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ payment.status_reason }}
                                </p>
                            </div>

                            <!-- Nobody approved it. Saying so plainly is the whole
                                 point: telling a member to approve a prompt their
                                 phone no longer has is what leaves them stuck. -->
                            <div
                                v-else-if="stalled"
                                class="rounded-xl border border-gold-300 bg-gold-50 px-4 py-3 dark:border-gold-400/30 dark:bg-gold-400/10"
                            >
                                <p
                                    class="text-sm font-medium text-card-foreground"
                                >
                                    That prompt was not approved in time
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        payment?.status_reason ??
                                        'Nothing was taken from your wallet. Start it again below.'
                                    }}
                                </p>
                            </div>

                            <AppButton
                                v-if="waitingOnPhone && !stalled"
                                block
                                variant="outline"
                                @click="checkPayment"
                            >
                                Check the payment
                            </AppButton>

                            <template v-if="abilities.pay">
                                <AppButton
                                    block
                                    :loading="paying === 'mobile_money'"
                                    :disabled="payForm.processing"
                                    @click="pay('mobile_money')"
                                >
                                    <template #icon
                                        ><Smartphone class="size-4"
                                    /></template>
                                    {{
                                        stalled
                                            ? 'Try again — send a new prompt'
                                            : `Pay ${formatMoney(payable)} now`
                                    }}
                                </AppButton>

                                <!-- The card never touches this application: the
                                     provider's own page takes it. -->
                                <AppButton
                                    v-if="widget"
                                    block
                                    variant="outline"
                                    :loading="paying === 'card'"
                                    :disabled="payForm.processing"
                                    @click="pay('card')"
                                >
                                    <template #icon
                                        ><CreditCard class="size-4"
                                    /></template>
                                    Pay by card instead
                                </AppButton>

                                <p class="text-xs text-muted-foreground">
                                    The prompt goes to the mobile money number
                                    on your record — approve it on your handset.
                                    Card opens the payment provider's own page;
                                    your card details never reach us. Either way
                                    it is for the full approved amount; the
                                    table cannot take part of it.
                                </p>
                            </template>
                        </template>
                    </div>
                </div>
            </AppCard>

            <!-- Outside the window the form is replaced entirely rather than
                 disabled, so there is nothing to fill in and be refused. -->
            <AppCard v-else-if="!isOpen">
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
