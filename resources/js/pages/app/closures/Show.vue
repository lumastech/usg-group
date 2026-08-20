<script setup lang="ts">
/**
 * One member's closure, walked through as a wizard.
 *
 * The statement is rendered line by line with the formula behind each figure,
 * because the member reading it will ask where the number came from. Nothing on
 * this page decides anything: the buttons follow the `abilities` the server
 * computed from the policy, and the amount is recomputed server-side when the two
 * signatures arrive — what is on screen is a view, never the input.
 */
import { Link, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    FileText,
    HeartHandshake,
    Lock,
    ShieldCheck,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    ConfirmDialog,
    FormField,
    MoneyText,
    SelectInput,
    StatusBadge,
    Stepper,
    TextareaInput,
    TextInput,
} from '@/components/unity';
import type { SelectOption, Step } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    ClosureCycle,
    ClosureMember,
    ClosureRow,
    ExecutedPayout,
    MemberDebt,
    NextOfKinOption,
    PayoutBreakdown,
    PayoutLine,
    RepaymentArrangement,
} from '@/types/payouts';

const props = defineProps<{
    member: ClosureMember;
    cycle: ClosureCycle;
    payoutCase: { value: string; label: string };
    breakdown: PayoutBreakdown;
    closure: ClosureRow;
    payout: ExecutedPayout | null;
    debt: MemberDebt | null;
    arrangement: RepaymentArrangement | null;
    nextOfKin: NextOfKinOption[];
    abilities: { execute: boolean; settleEarly: boolean };
}>();

const isSettled = computed(() => props.member.ledgers_frozen_at !== null);
const isDeceased = computed(() => props.member.status === 'deceased');
const isNegative = computed(() => props.breakdown.is_negative);

/** A death settled before share-out is an override, and costs a written reason. */
const needsOverrideNote = computed(
    () => !props.cycle.is_sharing_out && props.abilities.settleEarly,
);

const showGrantStep = computed(() => isDeceased.value);

const steps = computed<Step[]>(() => [
    { key: 'statement', label: 'Statement', description: 'What the ledgers say' },
    ...(showGrantStep.value
        ? [
              {
                  key: 'grant',
                  label: 'Funeral grant',
                  description: 'Handled by the fund',
              },
          ]
        : []),
    {
        key: 'terms',
        label: isNegative.value ? 'Terms' : 'Approval',
        description: isNegative.value
            ? 'Nothing is payable'
            : 'Two signatures',
    },
    {
        key: 'done',
        label: isNegative.value ? 'Recorded' : 'Voucher',
        description: isNegative.value ? 'Debt on record' : 'Print and sign',
    },
]);

const stepIndex = ref(isSettled.value ? steps.value.length - 1 : 0);
const dialogOpen = ref(false);

const form = useForm({
    early_settlement_note: '',
    agreed_terms: '',
    next_of_kin_id: null as number | null,
    agreed_on: new Date().toISOString().slice(0, 10),
    note: '',
    approver_email: '',
    approver_password: '',
});

const kinOptions = computed<SelectOption[]>(() =>
    props.nextOfKin.map((kin) => ({
        value: kin.id,
        label: `${kin.name} — ${kin.relationship_label}`,
    })),
);

/** Bold rules under the arithmetic; a note explains without counting. */
function lineClass(line: PayoutLine): string {
    if (line.kind === 'total') {
        return 'border-t border-foreground/70 font-semibold';
    }

    if (line.kind === 'subtotal') {
        return 'border-t border-border font-medium';
    }

    return line.kind === 'note' ? 'text-muted-foreground italic' : '';
}

const canSubmit = computed(() => {
    if (!props.abilities.execute || isSettled.value) {
        return false;
    }

    if (needsOverrideNote.value && form.early_settlement_note.trim() === '') {
        return false;
    }

    return !(isNegative.value && isDeceased.value && form.agreed_terms.trim() === '');
});

const summary = computed(() =>
    isNegative.value
        ? `Record ${formatMoney(props.breakdown.shortfall_ngwee)} owed by ${props.member.full_name}`
        : `Pay ${formatMoney(props.breakdown.payable_ngwee)} to ${props.member.full_name}`,
);

function confirm(payload: {
    approver_email?: string;
    approver_password?: string;
}): void {
    form.approver_email = payload.approver_email ?? '';
    form.approver_password = payload.approver_password ?? '';

    form.post(`/app/closures/${props.member.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
            form.reset('approver_email', 'approver_password');
        },
    });
}
</script>

<template>
    <AdminLayout
        :title="`Closure — ${member.full_name}`"
        :heading="member.full_name"
        :description="`Member ${member.member_number} · ${payoutCase.label}`"
    >
        <div class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <Link
                    href="/app/closures"
                    class="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="size-4" />
                    All closures
                </Link>

                <div class="flex items-center gap-2">
                    <StatusBadge :status="member.status" :label="member.status_label" />
                    <StatusBadge
                        v-if="isSettled"
                        status="settled"
                        label="Ledgers closed"
                    />
                </div>
            </div>

            <Stepper :steps="steps" :current="stepIndex" />

            <!-- Step 1 — the itemised statement. -->
            <AppCard
                title="Statement"
                :description="`Computed from the ledgers${breakdown.interest_cutoff ? `, interest struck at ${breakdown.interest_cutoff}` : ''}.`"
            >
                <table class="w-full text-sm">
                    <tbody>
                        <tr
                            v-for="(line, index) in breakdown.lines"
                            :key="`${line.label}-${index}`"
                            :class="lineClass(line)"
                        >
                            <td class="py-2 pr-4 align-top">
                                <div class="text-foreground">{{ line.label }}</div>
                                <div class="mt-0.5 text-xs text-muted-foreground">
                                    {{ line.formula }}
                                </div>
                            </td>
                            <td class="py-2 text-right align-top whitespace-nowrap">
                                <MoneyText
                                    v-if="line.kind !== 'note'"
                                    :ngwee="line.amount_ngwee"
                                    :signed="line.kind === 'debit'"
                                />
                                <span v-else class="text-xs text-muted-foreground">
                                    not payable
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div
                    v-if="isNegative"
                    class="mt-4 flex items-start gap-3 rounded-lg border border-red-200 bg-red-50/60 p-4 text-sm dark:border-red-500/25 dark:bg-red-500/5"
                >
                    <TriangleAlert class="mt-0.5 size-4 shrink-0 text-destructive" />
                    <p class="text-muted-foreground">
                        This closure comes out at
                        <MoneyText :ngwee="breakdown.net_payable_ngwee" signed />. Nothing
                        is paid: the
                        <span class="font-medium text-foreground">{{
                            formatMoney(breakdown.shortfall_ngwee)
                        }}</span>
                        shortfall is recorded
                        {{
                            isDeceased
                                ? 'as a repayment arrangement with the next of kin'
                                : 'as a debt in this member’s name'
                        }}.
                    </p>
                </div>
            </AppCard>

            <!-- Step 2 — the funeral grant, which belongs to the fund, not to this sum. -->
            <AppCard
                v-if="showGrantStep"
                title="Funeral grant"
                description="Paid by the Social Fund. It is shown here so both matters are settled together — it is never netted into the statement."
            >
                <div class="flex items-center gap-3 text-sm">
                    <HeartHandshake class="size-5 text-gold-600 dark:text-gold-400" />
                    <template v-if="closure.funeral_grant">
                        <StatusBadge
                            :status="closure.funeral_grant.status"
                            :label="closure.funeral_grant.status_label"
                            size="sm"
                        />
                        <MoneyText :ngwee="closure.funeral_grant.amount_ngwee" />
                        <Link
                            href="/app/fund/claims"
                            class="text-brand-700 hover:underline dark:text-brand-400"
                        >
                            Open the claims register
                        </Link>
                    </template>
                    <template v-else>
                        <span class="text-muted-foreground">
                            No funeral grant claim stands against this member.
                        </span>
                        <Link
                            href="/app/fund/claims"
                            class="text-brand-700 hover:underline dark:text-brand-400"
                        >
                            Raise one
                        </Link>
                    </template>
                </div>
            </AppCard>

            <!-- Step 3 — what the committee has to write down before signing. -->
            <AppCard
                v-if="!isSettled"
                :title="isNegative ? 'Record the shortfall' : 'Execute the payout'"
                :description="
                    abilities.execute
                        ? 'Two committee members sign for this: a treasurer executes, and the chair confirms on the same device.'
                        : 'You do not hold the permission to settle this closure.'
                "
            >
                <div class="space-y-4">
                    <div
                        v-if="needsOverrideNote"
                        class="space-y-3 rounded-lg border border-gold-200 bg-gold-50/50 p-4 dark:border-gold-400/25 dark:bg-gold-400/5"
                    >
                        <p class="text-sm font-medium text-foreground">
                            Settling before share-out
                        </p>
                        <p class="text-xs text-muted-foreground">
                            The cycle is {{ cycle.status_label }}. A death may be settled
                            early on compassionate grounds; the reason is stored on the
                            voucher.
                        </p>
                        <FormField
                            label="Reason for early settlement"
                            :error="form.errors.early_settlement_note"
                            required
                        >
                            <template #default="{ id, invalid }">
                                <TextareaInput
                                    :id="id"
                                    v-model="form.early_settlement_note"
                                    :invalid="invalid"
                                    :rows="2"
                                    placeholder="e.g. The family is burying her on Saturday and needs the funds."
                                />
                            </template>
                        </FormField>
                    </div>

                    <template v-if="isNegative && isDeceased">
                        <FormField
                            label="Next of kin"
                            :error="form.errors.next_of_kin_id"
                            hint="Whoever the member nominated. Leave blank to use their first nominee."
                        >
                            <template #default="{ id, invalid }">
                                <SelectInput
                                    :id="id"
                                    v-model="form.next_of_kin_id"
                                    :options="kinOptions"
                                    :invalid="invalid"
                                    placeholder="Select a nominee"
                                />
                            </template>
                        </FormField>

                        <FormField
                            label="Agreed terms"
                            :error="form.errors.agreed_terms"
                            required
                        >
                            <template #default="{ id, invalid }">
                                <TextareaInput
                                    :id="id"
                                    v-model="form.agreed_terms"
                                    :invalid="invalid"
                                    :rows="3"
                                    placeholder="e.g. K1,000 a month from the estate for five months, starting March."
                                />
                            </template>
                        </FormField>

                        <FormField label="Agreed on" :error="form.errors.agreed_on">
                            <template #default="{ id, invalid }">
                                <TextInput
                                    :id="id"
                                    v-model="form.agreed_on"
                                    type="date"
                                    :invalid="invalid"
                                />
                            </template>
                        </FormField>
                    </template>

                    <FormField label="Note" :error="form.errors.note">
                        <template #default="{ id, invalid }">
                            <TextareaInput
                                :id="id"
                                v-model="form.note"
                                :invalid="invalid"
                                :rows="2"
                                placeholder="Anything the group should read beside this settlement."
                            />
                        </template>
                    </FormField>

                    <p
                        v-if="form.errors.approver_email"
                        class="text-xs font-medium text-destructive"
                        role="alert"
                    >
                        {{ form.errors.approver_email }}
                    </p>

                    <div class="flex justify-end">
                        <AppButton
                            variant="gold"
                            :icon="ShieldCheck"
                            :disabled="!canSubmit"
                            @click="dialogOpen = true"
                        >
                            {{ isNegative ? 'Record and close ledgers' : 'Execute payout' }}
                        </AppButton>
                    </div>
                </div>
            </AppCard>

            <!-- Step 4 — what came of it. -->
            <AppCard
                v-if="payout"
                title="Payout executed"
                description="The member's ledgers were closed at this moment. The voucher shows the position as it stood."
            >
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-muted-foreground">Paid</dt>
                        <dd class="mt-0.5 text-lg font-semibold">
                            <MoneyText :ngwee="payout.amount_ngwee" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Signed by</dt>
                        <dd class="mt-0.5">
                            {{ payout.executed_by }} and {{ payout.second_approver }}
                        </dd>
                    </div>
                    <div v-if="payout.early_settlement_note" class="sm:col-span-2">
                        <dt class="text-xs text-muted-foreground">
                            Settled before share-out
                        </dt>
                        <dd class="mt-0.5">{{ payout.early_settlement_note }}</dd>
                    </div>
                </dl>

                <div class="mt-4 flex items-center gap-3">
                    <AppButton
                        as="a"
                        :href="`/app/payouts/${payout.id}/voucher`"
                        variant="secondary"
                        :icon="FileText"
                    >
                        Download voucher
                    </AppButton>
                    <span
                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <Lock class="size-3.5" />
                        Ledgers closed {{ member.ledgers_frozen_at?.slice(0, 10) }}
                    </span>
                </div>
            </AppCard>

            <AppCard
                v-if="debt"
                title="Debt on record"
                description="Nothing was paid. This is what the member owes the group."
            >
                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <MoneyText :ngwee="debt.amount_owed_ngwee" class="text-lg font-semibold" />
                    <StatusBadge :status="debt.status" :label="debt.status_label" size="sm" />
                    <span v-if="debt.note" class="text-muted-foreground">{{ debt.note }}</span>
                </div>
            </AppCard>

            <AppCard
                v-if="arrangement"
                title="Repayment arrangement"
                description="Agreed with the next of kin in place of a payout."
            >
                <dl class="grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-muted-foreground">Owed</dt>
                        <dd class="mt-0.5 text-lg font-semibold">
                            <MoneyText :ngwee="arrangement.amount_owed_ngwee" />
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Next of kin</dt>
                        <dd class="mt-0.5">{{ arrangement.next_of_kin ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-muted-foreground">Terms</dt>
                        <dd class="mt-0.5">{{ arrangement.agreed_terms }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Status</dt>
                        <dd class="mt-0.5">
                            <StatusBadge
                                :status="arrangement.status"
                                :label="arrangement.status_label"
                                size="sm"
                            />
                        </dd>
                    </div>
                </dl>
            </AppCard>
        </div>

        <ClientOnly>
            <ConfirmDialog
                v-model:open="dialogOpen"
                variant="dual-approval"
                :title="isNegative ? 'Record the shortfall' : 'Execute this payout'"
                :message="
                    isNegative
                        ? 'This closes the member’s ledgers for good. Nothing may be posted against them afterwards.'
                        : 'The amount is recomputed from the ledgers as this is confirmed, and the member’s ledgers are closed.'
                "
                :action-summary="summary"
                :confirm-label="isNegative ? 'Record and close' : 'Execute payout'"
                :errors="form.errors"
                :processing="form.processing"
                @confirm="confirm"
            />
        </ClientOnly>
    </AdminLayout>
</template>
