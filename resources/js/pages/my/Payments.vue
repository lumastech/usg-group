<script setup lang="ts">
/**
 * Paying what you owe, from your own phone.
 *
 * Two ways out of one screen. "Card or wallet" opens the provider's own payment page —
 * card details never touch this application, which is what keeps the group out of PCI
 * scope. "Prompt my phone" pushes a request straight to the member's mobile money and
 * they approve it on the handset, which is the flow that works on a slow connection.
 *
 * Whichever way, the browser is never believed about whether the money moved: the
 * verify step asks the provider, and the ledgers only take it from there.
 */
import { useForm } from '@inertiajs/vue3';
import { CreditCard, Smartphone } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    FormField,
    MoneyInput,
    MoneyText,
    SelectInput,
    StatusBadge,
    TextInput,
} from '@/components/unity';
import { usePaymentWidget } from '@/composables/usePaymentWidget';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import type { PaymentIntent, PaymentWidgetConfig } from '@/types/payments';

const props = defineProps<{
    payments: PaymentIntent[];
    widget: PaymentWidgetConfig | null;
    owing: {
        savings_ngwee: number | null;
        joining_fee_ngwee: number | null;
        social_fund_ngwee: number | null;
        loan: {
            id: number;
            balance_ngwee: number;
            next_due_ngwee: number | null;
        } | null;
    } | null;
    month: { id: number; label: string } | null;
}>();

/* Card details are only ever typed into the provider's own page; the same handover
   the declaration screen uses, so the two cannot drift apart. */
const { error: widgetError, openIfStarted, verify } = usePaymentWidget();

const form = useForm({
    purpose: 'savings_contribution',
    amount_ngwee: null as number | null,
    channel: 'mobile_money',
    cycle_month_id: null as number | null,
    loan_id: null as number | null,
    phone: '',
});

const busy = ref(false);

const purposes = computed(() => {
    const options: { value: string; label: string }[] = [];

    if (
        props.owing?.savings_ngwee !== null &&
        props.owing?.savings_ngwee !== undefined
    ) {
        options.push({
            value: 'savings_contribution',
            label: 'Monthly savings',
        });
    }

    if (props.owing?.joining_fee_ngwee) {
        options.push({ value: 'joining_fee', label: 'Joining fee' });
    }

    if (props.owing?.social_fund_ngwee) {
        options.push({
            value: 'social_fund_contribution',
            label: 'Social fund',
        });
    }

    if (props.owing?.loan) {
        options.push({ value: 'loan_repayment', label: 'Loan repayment' });
    }

    return options;
});

/** What the group is expecting for whatever the member has chosen to pay. */
const suggested = computed<number | null>(() => {
    switch (form.purpose) {
        case 'savings_contribution':
            return props.owing?.savings_ngwee ?? null;
        case 'joining_fee':
            return props.owing?.joining_fee_ngwee ?? null;
        case 'social_fund_contribution':
            return props.owing?.social_fund_ngwee ?? null;
        case 'loan_repayment':
            return props.owing?.loan?.next_due_ngwee ?? null;
        default:
            return null;
    }
});

/** Savings move in K500 steps; nothing else does. */
const step = computed(() =>
    form.purpose === 'savings_contribution' ? 50_000 : 0,
);

function useSuggested(): void {
    form.amount_ngwee = suggested.value;
}

function pay(channel: 'mobile_money' | 'card'): void {
    busy.value = true;

    form.transform((data) => ({
        ...data,
        channel,
        cycle_month_id: props.month?.id ?? null,
        loan_id:
            data.purpose === 'loan_repayment'
                ? (props.owing?.loan?.id ?? null)
                : null,
    })).post('/my/payments', {
        preserveScroll: true,
        onSuccess: () => {
            if (openIfStarted()) {
                return;
            }

            form.reset('amount_ngwee');
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

function when(value: string | null): string {
    return value
        ? new Date(value).toLocaleDateString('en-GB', { dateStyle: 'medium' })
        : '';
}
</script>

<template>
    <MemberLayout
        title="Pay"
        heading="Pay"
        description="Send your savings, your repayment or your fund contribution from this phone."
    >
        <div class="space-y-4">
            <AppCard
                v-if="!widget && purposes.length === 0"
                title="Nothing to pay"
            >
                <EmptyState
                    title="You are up to date"
                    description="There is nothing outstanding for you to pay right now."
                />
            </AppCard>

            <AppCard
                v-else
                :title="month ? `Pay for ${month.label}` : 'Make a payment'"
                description="The provider's fee is added to what you pay, so the group receives the round figure."
            >
                <div class="space-y-4">
                    <FormField
                        label="What are you paying?"
                        :error="form.errors.purpose"
                    >
                        <template #default="{ id, invalid }">
                            <SelectInput
                                :id="id"
                                v-model="form.purpose"
                                :invalid="invalid"
                                :options="purposes"
                            />
                        </template>
                    </FormField>

                    <FormField
                        label="Amount"
                        :error="form.errors.amount_ngwee"
                        :hint="
                            form.purpose === 'savings_contribution'
                                ? 'Savings move in K500 steps.'
                                : undefined
                        "
                    >
                        <template #default="{ id, invalid }">
                            <MoneyInput
                                :id="id"
                                v-model="form.amount_ngwee"
                                :step="step"
                                :invalid="invalid"
                            />
                        </template>
                    </FormField>

                    <button
                        v-if="suggested"
                        type="button"
                        class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                        @click="useSuggested"
                    >
                        Use <MoneyText :ngwee="suggested" />, which is what is
                        expected
                    </button>

                    <FormField
                        label="Mobile money number"
                        hint="Leave blank to use the number on your record."
                        :error="form.errors.phone"
                    >
                        <template #default="{ id, invalid }">
                            <TextInput
                                :id="id"
                                v-model="form.phone"
                                :invalid="invalid"
                                inputmode="tel"
                                placeholder="0977 000 000"
                            />
                        </template>
                    </FormField>

                    <!-- The provider's own page never came up. The spinner it
                         leaves behind says nothing, and the phone prompt is still
                         there to be used. -->
                    <p
                        v-if="widgetError"
                        class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-900 dark:border-red-500/30 dark:bg-red-950 dark:text-red-100"
                    >
                        {{ widgetError }}
                    </p>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <AppButton
                            block
                            :loading="busy && form.channel === 'mobile_money'"
                            :disabled="!form.amount_ngwee"
                            @click="pay('mobile_money')"
                        >
                            <template #icon
                                ><Smartphone class="size-4"
                            /></template>
                            Prompt my phone
                        </AppButton>

                        <AppButton
                            v-if="widget"
                            block
                            variant="outline"
                            :loading="busy && form.channel === 'card'"
                            :disabled="!form.amount_ngwee"
                            @click="pay('card')"
                        >
                            <template #icon
                                ><CreditCard class="size-4"
                            /></template>
                            Card or wallet
                        </AppButton>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Prompting your phone sends a request to your mobile
                        money — approve it on the handset. Card or wallet opens
                        the payment provider's own page; your card details never
                        reach this system.
                    </p>
                </div>
            </AppCard>

            <AppCard title="Your payments" flush>
                <EmptyState
                    v-if="payments.length === 0"
                    title="No payments yet"
                    description="Anything you pay from this phone will be listed here."
                />

                <ul v-else class="divide-y">
                    <li
                        v-for="payment in payments"
                        :key="payment.id"
                        class="flex items-center justify-between gap-3 p-4"
                    >
                        <div class="min-w-0">
                            <p class="font-medium">
                                <MoneyText :ngwee="payment.amount_ngwee" />
                                <span class="text-muted-foreground">
                                    · {{ payment.purpose_label }}</span
                                >
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    payment.has_stalled
                                        ? 'Not approved in time — nothing was taken'
                                        : payment.member_status_label
                                }}
                                <span v-if="payment.created_at">
                                    · {{ when(payment.created_at) }}</span
                                >
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <StatusBadge
                                :status="payment.status"
                                :label="payment.status_label"
                                size="sm"
                            />
                            <AppButton
                                v-if="
                                    payment.status === 'pending' ||
                                    payment.status === 'awaiting-authorization'
                                "
                                size="sm"
                                variant="ghost"
                                @click="verify(payment.id)"
                            >
                                {{ payment.has_stalled ? 'Clear' : 'Check' }}
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>
    </MemberLayout>
</template>
