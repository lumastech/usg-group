<script setup lang="ts">
/**
 * The member's own wallet.
 *
 * Two rails and nothing else. Money in is a top-up, which nothing refuses — the group
 * will always take money into your own account and work out what it is for afterwards.
 * Money out is a withdrawal to somewhere you have already had verified.
 *
 * Everything you pay the group happens between wallets and never leaves this system,
 * so a payment the rules will not accept is refused here, while you are still holding
 * the money — rather than after it has gone.
 */
import { Link, useForm } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowUpRight,
    CreditCard,
    Smartphone,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    FormField,
    MoneyInput,
    MoneyText,
    SelectInput,
    StatCard,
    TextInput,
} from '@/components/unity';
import { usePaymentWidget } from '@/composables/usePaymentWidget';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import type {
    PaymentIntent,
    PaymentWidgetConfig,
    PayoutDestination,
} from '@/types/payments';
import type { Wallet, WalletEntry, WalletLimits } from '@/types/wallets';

const props = defineProps<{
    wallet: Wallet;
    statement: WalletEntry[];
    destinations: PayoutDestination[];
    /** Top-ups already started that have not reached the wallet yet. */
    topUps: PaymentIntent[];
    widget: PaymentWidgetConfig | null;
    limits: WalletLimits;
    phone: string | null;
}>();

/* The same handover the other payment screens use, pointed at this screen's own
   verify route — the browser is never believed about whether money moved. */
const {
    error: widgetError,
    openIfStarted,
    verify,
} = usePaymentWidget({
    verifyPath: (id: number) => `/my/wallet/${id}/verify`,
});

const topUp = useForm({
    amount_ngwee: null as number | null,
    channel: 'mobile_money',
    phone: '',
});

const withdrawal = useForm({
    amount_ngwee: null as number | null,
    payout_destination_id:
        props.destinations.find((d) => d.is_default)?.id ?? null,
});

const busy = ref(false);
const showWithdrawal = ref(false);

const destinationOptions = computed(() =>
    props.destinations.map((destination) => ({
        value: destination.id,
        label: destination.label,
    })),
);

const canWithdraw = computed(
    () =>
        props.destinations.length > 0 &&
        props.limits.available_ngwee >= props.limits.withdrawal_min_ngwee,
);

function startTopUp(channel: 'mobile_money' | 'card'): void {
    busy.value = true;

    topUp
        .transform((data) => ({ ...data, channel }))
        .post('/my/wallet/top-up', {
            preserveScroll: true,
            onSuccess: () => {
                if (openIfStarted()) {
                    return;
                }

                topUp.reset('amount_ngwee');
            },
            onFinish: () => {
                busy.value = false;
            },
        });
}

function withdraw(): void {
    withdrawal.post('/my/wallet/withdraw', {
        preserveScroll: true,
        onSuccess: () => {
            withdrawal.reset('amount_ngwee');
            showWithdrawal.value = false;
        },
    });
}

/**
 * Whether asking the provider about this one is still worth doing.
 *
 * Everything in flight is: nothing here can know the money arrived until the provider
 * is asked, and a member watching an unchanged balance with nothing to press is the
 * one who pays a second time. A payment already parked for a treasurer is not — that
 * one is waiting on a person, and another check will not move it.
 */
function mayCheck(payment: PaymentIntent): boolean {
    return payment.status !== 'needs-attention';
}

/** Asks the provider what became of a top-up; the browser is never believed. */
function checkTopUp(payment: PaymentIntent): void {
    verify(payment.id);
}

function when(value: string | null): string {
    return value
        ? new Date(value).toLocaleDateString('en-GB', { dateStyle: 'medium' })
        : '';
}
</script>

<template>
    <MemberLayout
        title="Wallet"
        heading="Your wallet"
        description="Money you are holding, before it becomes savings or after the group has paid you."
    >
        <div class="space-y-4">
            <StatCard
                label="In your wallet"
                :ngwee="wallet.balance_ngwee"
                accent="gold"
                :hint="
                    wallet.status === 'open'
                        ? 'Yours to pay the group with, or to take out.'
                        : `This wallet is ${wallet.status_label.toLowerCase()}.`
                "
            />

            <!-- Money the member has started putting in that has not landed yet.
                 The credit comes from the provider's webhook or the poller, and a
                 member who approves a prompt is quicker than both — so the payment is
                 on the screen with a way to ask about it, rather than nothing at all
                 while the balance sits still. -->
            <AppCard
                v-if="topUps.length > 0"
                title="On its way in"
                description="Money you have started putting in. It reaches your balance once the provider confirms it."
            >
                <ul class="space-y-3">
                    <li
                        v-for="payment in topUps"
                        :key="payment.id"
                        class="rounded-xl border px-4 py-3"
                        :class="
                            payment.has_stalled
                                ? 'border-gold-300 bg-gold-50 dark:border-gold-400/30 dark:bg-gold-400/10'
                                : 'border-border bg-muted'
                        "
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p
                                    class="text-sm font-medium text-card-foreground"
                                >
                                    {{
                                        payment.has_stalled
                                            ? 'That prompt was not approved in time'
                                            : payment.member_status_label
                                    }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        payment.has_stalled
                                            ? (payment.status_reason ??
                                              'Nothing was taken. Start it again below.')
                                            : (payment.status_reason ??
                                              payment.channel_label)
                                    }}
                                </p>
                            </div>

                            <MoneyText
                                :ngwee="payment.amount_ngwee"
                                class="shrink-0 font-medium tabular-nums"
                            />
                        </div>

                        <AppButton
                            v-if="mayCheck(payment)"
                            class="mt-3"
                            block
                            variant="outline"
                            @click="checkTopUp(payment)"
                        >
                            {{
                                payment.has_stalled
                                    ? 'Clear it'
                                    : 'Check the payment'
                            }}
                        </AppButton>
                    </li>
                </ul>
            </AppCard>

            <AppCard
                title="Put money in"
                description="Nothing is decided here. Money in your wallet is still yours until you pay it to the group."
            >
                <div class="space-y-4">
                    <FormField
                        label="Amount"
                        :error="topUp.errors.amount_ngwee"
                    >
                        <template #default="{ id, invalid }">
                            <MoneyInput
                                :id="id"
                                v-model="topUp.amount_ngwee"
                                :min="limits.top_up_min_ngwee"
                                :invalid="invalid"
                            />
                        </template>
                    </FormField>

                    <FormField
                        label="Mobile money number"
                        hint="Leave blank to use the number on your record."
                        :error="topUp.errors.phone"
                    >
                        <template #default="{ id, invalid }">
                            <TextInput
                                :id="id"
                                v-model="topUp.phone"
                                :invalid="invalid"
                                inputmode="tel"
                                :placeholder="phone ?? '0977 000 000'"
                            />
                        </template>
                    </FormField>

                    <!-- The provider's own page never came up. The phone prompt
                         is still there to be used. -->
                    <p
                        v-if="widgetError"
                        class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-xs text-red-900 dark:border-red-500/30 dark:bg-red-950 dark:text-red-100"
                    >
                        {{ widgetError }}
                    </p>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <AppButton
                            block
                            :loading="busy && topUp.channel === 'mobile_money'"
                            :disabled="!topUp.amount_ngwee"
                            @click="startTopUp('mobile_money')"
                        >
                            <template #icon>
                                <Smartphone class="size-4" />
                            </template>
                            Prompt my phone
                        </AppButton>

                        <AppButton
                            v-if="widget"
                            block
                            variant="outline"
                            :loading="busy && topUp.channel === 'card'"
                            :disabled="!topUp.amount_ngwee"
                            @click="startTopUp('card')"
                        >
                            <template #icon>
                                <CreditCard class="size-4" />
                            </template>
                            Card or wallet
                        </AppButton>
                    </div>
                </div>
            </AppCard>

            <AppCard
                title="Take money out"
                description="Your own money, sent where you have already told us to send it."
            >
                <EmptyState
                    v-if="destinations.length === 0"
                    title="Nowhere to send it yet"
                    description="Add a mobile money number or bank account first. We check whose account it is before anything is sent."
                >
                    <template #action>
                        <Link
                            href="/my/destinations"
                            class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                        >
                            Add a destination
                        </Link>
                    </template>
                </EmptyState>

                <div v-else class="space-y-4">
                    <p class="text-sm text-muted-foreground">
                        You can take out
                        <MoneyText :ngwee="limits.available_ngwee" />, after the
                        transfer fee of about
                        <MoneyText :ngwee="limits.withdrawal_fee_ngwee" /> is
                        allowed for. The fee is yours, not the group's.
                    </p>

                    <template v-if="showWithdrawal">
                        <FormField
                            label="Amount"
                            :error="withdrawal.errors.amount_ngwee"
                        >
                            <template #default="{ id, invalid }">
                                <MoneyInput
                                    :id="id"
                                    v-model="withdrawal.amount_ngwee"
                                    :min="limits.withdrawal_min_ngwee"
                                    :max="limits.available_ngwee"
                                    :invalid="invalid"
                                />
                            </template>
                        </FormField>

                        <FormField
                            label="Send it to"
                            :error="withdrawal.errors.payout_destination_id"
                        >
                            <template #default="{ id, invalid }">
                                <SelectInput
                                    :id="id"
                                    v-model="withdrawal.payout_destination_id"
                                    :invalid="invalid"
                                    :options="destinationOptions"
                                />
                            </template>
                        </FormField>

                        <div class="grid gap-2 sm:grid-cols-2">
                            <AppButton
                                block
                                :loading="withdrawal.processing"
                                :disabled="!withdrawal.amount_ngwee"
                                @click="withdraw"
                            >
                                <template #icon>
                                    <ArrowUpRight class="size-4" />
                                </template>
                                Send it
                            </AppButton>

                            <AppButton
                                block
                                variant="ghost"
                                @click="showWithdrawal = false"
                            >
                                Cancel
                            </AppButton>
                        </div>
                    </template>

                    <AppButton
                        v-else
                        variant="outline"
                        :disabled="!canWithdraw"
                        @click="showWithdrawal = true"
                    >
                        <template #icon>
                            <ArrowUpRight class="size-4" />
                        </template>
                        Withdraw
                    </AppButton>
                </div>
            </AppCard>

            <AppCard title="Everything that has moved" flush>
                <EmptyState
                    v-if="statement.length === 0"
                    title="Nothing yet"
                    description="Money you put in, pay to the group, or are paid by the group will be listed here."
                />

                <ul v-else class="divide-y">
                    <li
                        v-for="entry in statement"
                        :key="entry.id"
                        class="flex items-center justify-between gap-3 p-4"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <component
                                :is="
                                    entry.is_credit
                                        ? ArrowDownLeft
                                        : ArrowUpRight
                                "
                                class="size-4 shrink-0"
                                :class="
                                    entry.is_credit
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-muted-foreground'
                                "
                            />
                            <div class="min-w-0">
                                <p class="font-medium">
                                    {{ entry.type_label }}
                                </p>
                                <p
                                    class="truncate text-sm text-muted-foreground"
                                >
                                    {{ entry.note ?? entry.counterparty ?? '' }}
                                    <span v-if="entry.occurred_on">
                                        · {{ when(entry.occurred_on) }}</span
                                    >
                                </p>
                            </div>
                        </div>

                        <MoneyText
                            :ngwee="entry.amount_ngwee"
                            class="shrink-0 font-medium tabular-nums"
                            :class="
                                entry.is_credit
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : ''
                            "
                        />
                    </li>
                </ul>
            </AppCard>
        </div>
    </MemberLayout>
</template>
