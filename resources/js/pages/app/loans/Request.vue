<script setup lang="ts">
/**
 * The loan request wizard: pick the member, choose the amount, review, submit.
 *
 * The eligibility panel is live — every change of member or amount asks the server the
 * same question the submit will ask, so the ceiling, the tenor and the preview schedule
 * on screen are the server's answers rather than arithmetic repeated here. Refusals
 * render one line per failed condition, in the words the server chose.
 */
import { router, useForm, useHttp } from '@inertiajs/vue3';
import { CircleCheck, CircleX, Info } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    FormField,
    MoneyInput,
    SelectInput,
    StatCard,
    Stepper,
    TextareaInput,
} from '@/components/unity';
import type { Step } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { LoanEligibility, LoanRules } from '@/types/loans';

const props = defineProps<{
    members: { id: number; member_number: number; full_name: string }[];
    rules: LoanRules | null;
    canOverride: boolean;
}>();

const STEPS: Step[] = [
    { key: 'member', label: 'Member', description: 'Who is borrowing' },
    {
        key: 'amount',
        label: 'Amount',
        description: 'How much, and for how long',
    },
    { key: 'review', label: 'Review', description: 'Confirm and submit' },
];

const step = ref(0);

const form = useForm({
    member_id: null as number | null,
    principal_ngwee: null as number | null,
    discretion_note: '',
});

/** Asks the server the same eligibility question the submit will ask. */
const check = useHttp<
    { member_id: number | null; principal_ngwee: number; overriding: boolean },
    LoanEligibility
>({ member_id: null, principal_ngwee: 0, overriding: false });

const eligibility = ref<LoanEligibility | null>(null);

const memberOptions = computed(() =>
    props.members.map((member) => ({
        value: member.id,
        label: `${member.member_number}. ${member.full_name}`,
    })),
);

const selectedMember = computed(
    () => props.members.find((member) => member.id === form.member_id) ?? null,
);

const overriding = computed(() => form.discretion_note.trim().length > 0);

/**
 * Re-check whenever the member, the amount or the override note changes.
 *
 * The panel is allowed to lag a keystroke behind — it is guidance while typing, and
 * the request itself is re-checked server-side on submit regardless.
 */
watch(
    () => [form.member_id, form.principal_ngwee, overriding.value],
    () => {
        if (!form.member_id || !form.principal_ngwee) {
            eligibility.value = null;

            return;
        }

        check.member_id = form.member_id;
        check.principal_ngwee = form.principal_ngwee;
        check.overriding = overriding.value;

        check
            .post('/app/loans/eligibility')
            .then((result) => (eligibility.value = result))
            .catch(() => (eligibility.value = null));
    },
);

const canAdvance = computed(() => {
    if (step.value === 0) {
        return form.member_id !== null;
    }

    if (step.value === 1) {
        return (
            form.principal_ngwee !== null &&
            form.principal_ngwee > 0 &&
            eligibility.value?.eligible === true
        );
    }

    return true;
});

function submit(): void {
    form.transform((data) => ({
        ...data,
        discretion_note: data.discretion_note.trim() || null,
    })).post('/app/loans', { preserveScroll: true });
}
</script>

<template>
    <AdminLayout
        title="Request a loan"
        heading="New loan request"
        description="Captured at the trading table, approved by two committee members"
    >
        <AppCard v-if="!rules">
            <EmptyState
                title="No active cycle"
                description="Activate a cycle before capturing loan requests."
            />
        </AppCard>

        <div v-else class="mx-auto max-w-3xl space-y-6">
            <Stepper :steps="STEPS" :current="step" />

            <!-- Step 1: who is borrowing. -->
            <AppCard v-if="step === 0" title="Which member?">
                <FormField
                    label="Member"
                    :error="form.errors.member_id"
                    required
                >
                    <SelectInput
                        v-model="form.member_id"
                        :options="memberOptions"
                        placeholder="Choose a member…"
                    />
                </FormField>
            </AppCard>

            <!-- Step 2: the amount, with the live eligibility panel beside it. -->
            <template v-if="step === 1">
                <AppCard
                    title="How much?"
                    :description="`Loans start at ${formatMoney(rules.minimum_ngwee)} and run at ${rules.monthly_interest_bps / 100}% a month on the reducing balance.`"
                >
                    <FormField
                        label="Principal"
                        :error="form.errors.principal_ngwee"
                        required
                    >
                        <MoneyInput
                            v-model="form.principal_ngwee"
                            :min="rules.minimum_ngwee"
                            :max="eligibility?.ceiling_ngwee"
                        />
                    </FormField>

                    <FormField
                        v-if="canOverride && eligibility?.has_open_loan"
                        label="Discretion note"
                        hint="Members hold one loan at a time. A committee member may allow a second, with a written reason that stays on the record."
                        :error="form.errors.discretion_note"
                        class="mt-4"
                    >
                        <TextareaInput
                            v-model="form.discretion_note"
                            :rows="3"
                            placeholder="Why is a second loan being allowed?"
                        />
                    </FormField>
                </AppCard>

                <AppCard
                    title="Eligibility"
                    :description="selectedMember?.full_name"
                >
                    <p
                        v-if="!eligibility"
                        class="text-sm text-muted-foreground"
                    >
                        Enter an amount to see whether it can be lent.
                    </p>

                    <div v-else class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <StatCard
                                label="Savings"
                                :ngwee="eligibility.cumulative_savings_ngwee"
                                compact
                            />
                            <StatCard
                                label="Ceiling"
                                :ngwee="eligibility.ceiling_ngwee"
                                :hint="`${rules.max_loan_multiple}× savings`"
                                compact
                                accent="gold"
                            />
                            <StatCard
                                label="Term"
                                :value="
                                    eligibility.tenor_months
                                        ? `${eligibility.tenor_months} months`
                                        : '—'
                                "
                                :hint="
                                    eligibility.compressed
                                        ? `Compressed from ${eligibility.earned_tenor_months} to end by the deadline`
                                        : undefined
                                "
                                compact
                            />
                        </div>

                        <div
                            v-if="eligibility.eligible"
                            class="flex items-start gap-2 rounded-lg bg-brand-50 p-3 text-sm text-brand-800 ring-1 ring-brand-200 dark:bg-brand-400/15 dark:text-brand-100 dark:ring-brand-400/25"
                        >
                            <CircleCheck class="mt-0.5 size-4 shrink-0" />
                            <span>
                                Eligible.
                                {{
                                    eligibility.overridden
                                        ? 'The one-loan rule is being overridden by committee discretion.'
                                        : ''
                                }}
                            </span>
                        </div>

                        <!-- One line per failed condition, specific and in the server's words. -->
                        <ul v-else class="space-y-2">
                            <li
                                v-for="reason in eligibility.reasons"
                                :key="reason.code"
                                class="flex items-start gap-2 rounded-lg bg-red-50 p-3 text-sm text-red-700 ring-1 ring-red-200 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-500/25"
                            >
                                <CircleX class="mt-0.5 size-4 shrink-0" />
                                <span>{{ reason.message }}</span>
                            </li>
                        </ul>
                    </div>
                </AppCard>
            </template>

            <!-- Step 3: what is about to be recorded. -->
            <AppCard v-if="step === 2" title="Review">
                <dl class="divide-y divide-border text-sm">
                    <div class="flex justify-between gap-4 py-2.5">
                        <dt class="text-muted-foreground">Member</dt>
                        <dd class="font-medium">
                            {{ selectedMember?.full_name }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 py-2.5">
                        <dt class="text-muted-foreground">Principal</dt>
                        <dd class="tabular font-medium">
                            {{ formatMoney(form.principal_ngwee ?? 0) }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 py-2.5">
                        <dt class="text-muted-foreground">Term</dt>
                        <dd class="font-medium">
                            {{ eligibility?.tenor_months }} months
                        </dd>
                    </div>
                    <div
                        v-if="eligibility?.compressed"
                        class="flex justify-between gap-4 py-2.5"
                    >
                        <dt class="text-muted-foreground">Schedule</dt>
                        <dd class="font-medium text-gold-700">
                            Compressed to end by
                            {{ rules.final_repayment_date }}
                        </dd>
                    </div>
                    <div
                        v-if="overriding"
                        class="flex justify-between gap-4 py-2.5"
                    >
                        <dt class="text-muted-foreground">Discretion</dt>
                        <dd class="max-w-sm text-right font-medium">
                            {{ form.discretion_note }}
                        </dd>
                    </div>
                </dl>

                <p
                    class="mt-4 flex items-start gap-2 text-xs text-muted-foreground"
                >
                    <Info class="mt-0.5 size-3.5 shrink-0" />
                    The request goes to the committee. Two members must approve
                    it before it joins the disbursement queue, and eligibility
                    is checked again on the trading day.
                </p>
            </AppCard>

            <div class="flex items-center justify-between gap-3">
                <AppButton
                    variant="ghost"
                    :disabled="step === 0"
                    @click="step -= 1"
                >
                    Back
                </AppButton>

                <div class="flex items-center gap-2">
                    <AppButton
                        variant="outline"
                        @click="router.get('/app/loans')"
                    >
                        Cancel
                    </AppButton>
                    <AppButton
                        v-if="step < STEPS.length - 1"
                        :disabled="!canAdvance"
                        @click="step += 1"
                    >
                        Continue
                    </AppButton>
                    <AppButton
                        v-else
                        :loading="form.processing"
                        @click="submit"
                    >
                        Submit request
                    </AppButton>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
