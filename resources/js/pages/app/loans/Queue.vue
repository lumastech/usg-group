<script setup lang="ts">
/**
 * The trading-day disbursement queue.
 *
 * Approved loans are paid out in the order they were requested, so the list is ordered
 * and the buttons are worked top-down. Paying somebody out of turn is allowed, but the
 * dialog takes a typed reason first and that reason stays on their record.
 */
import { Link, useForm } from '@inertiajs/vue3';
import { Banknote, Clock, Users } from '@lucide/vue';
import { ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    EmptyState,
    FormField,
    Modal,
    StatCard,
    TextareaInput,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { Loan } from '@/types/loans';

const props = defineProps<{
    queue: { data: Loan[] };
    month: {
        id: number;
        label: string;
        disbursement_on: string;
        is_trading_day: boolean;
    } | null;
    committed_ngwee: number;
}>();

const jumping = ref<Loan | null>(null);

const form = useForm({ out_of_order_reason: '' });

/** The head of the queue goes straight through; anything else asks for a reason. */
function disburse(loan: Loan, position: number): void {
    if (position > 1) {
        jumping.value = loan;

        return;
    }

    form.out_of_order_reason = '';
    form.post(`/app/loans/${loan.id}/disburse`, { preserveScroll: true });
}

function disburseOutOfOrder(): void {
    if (!jumping.value) {
        return;
    }

    form.post(`/app/loans/${jumping.value.id}/disburse`, {
        preserveScroll: true,
        onSuccess: () => {
            jumping.value = null;
            form.reset();
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Disbursement queue"
        heading="Disbursement queue"
        :description="
            month
                ? `${month.label} — trading concludes ${month.disbursement_on}`
                : undefined
        "
    >
        <div class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Waiting"
                    :value="queue.data.length"
                    :icon="Users"
                    hint="Approved and unpaid"
                />
                <StatCard
                    label="Committed"
                    :ngwee="committed_ngwee"
                    :icon="Banknote"
                    accent="gold"
                    hint="What the fund owes the queue"
                />
                <StatCard
                    label="Trading day"
                    :value="month?.disbursement_on ?? '—'"
                    :icon="Clock"
                    :hint="
                        month?.is_trading_day
                            ? 'Today — disbursements are due'
                            : 'Not today'
                    "
                />
            </div>

            <AppCard
                title="First come, first served"
                description="Ordered by the moment each request was captured. Money is finite on the day, so the order is the fairness."
                flush
            >
                <div v-if="queue.data.length === 0" class="p-5">
                    <EmptyState
                        title="Nothing in the queue"
                        description="Approved loans appear here, oldest request first."
                    />
                </div>

                <ol v-else class="divide-y divide-border">
                    <li
                        v-for="(loan, index) in queue.data"
                        :key="loan.id"
                        class="flex flex-wrap items-center gap-4 px-5 py-4"
                    >
                        <span
                            class="tabular grid size-9 shrink-0 place-items-center rounded-full bg-brand-50 text-sm font-semibold text-brand-700 dark:bg-brand-400/15 dark:text-brand-200"
                        >
                            {{ index + 1 }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/app/loans/${loan.id}`"
                                class="font-medium hover:text-brand-700"
                            >
                                {{ loan.member_name }}
                            </Link>
                            <p class="text-xs text-muted-foreground">
                                Requested
                                {{ loan.requested_at?.slice(0, 10) }} ·
                                {{ loan.tenor_months }} month term
                            </p>
                        </div>

                        <span class="tabular text-sm font-semibold">
                            {{ formatMoney(loan.principal_ngwee) }}
                        </span>

                        <AppButton
                            v-if="loan.abilities.disburse"
                            size="sm"
                            :variant="index === 0 ? 'primary' : 'outline'"
                            :loading="form.processing"
                            @click="disburse(loan, index + 1)"
                        >
                            {{ index === 0 ? 'Disburse' : 'Disburse early' }}
                        </AppButton>
                    </li>
                </ol>
            </AppCard>
        </div>

        <ClientOnly>
            <Modal
                :open="jumping !== null"
                title="Pay this loan out of turn"
                :description="`${jumping?.member_name} is not at the head of the queue. The reason is kept on the loan.`"
                @close="jumping = null"
            >
                <FormField
                    label="Reason"
                    :error="form.errors.out_of_order_reason"
                    required
                >
                    <TextareaInput
                        v-model="form.out_of_order_reason"
                        :rows="3"
                        placeholder="Medical emergency; agreed by the committee on the day."
                    />
                </FormField>

                <template #footer>
                    <AppButton variant="ghost" @click="jumping = null">
                        Cancel
                    </AppButton>
                    <AppButton
                        :loading="form.processing"
                        @click="disburseOutOfOrder"
                    >
                        Disburse
                    </AppButton>
                </template>
            </Modal>
        </ClientOnly>
    </AdminLayout>
</template>
