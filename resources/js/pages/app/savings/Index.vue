<script setup lang="ts">
/**
 * The savings ledger, laid out as the group's workbook lays it out: members down the
 * side, a Savings and an Interest column for each month, totals along the foot.
 *
 * Nothing is computed here — the matrix, its totals and the entry rules all arrive
 * from the server, so this screen and the exported sheet can never disagree.
 */
import { router, useForm } from '@inertiajs/vue3';
import { Coins, Download, PiggyBank, Plus, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    EmptyState,
    FormField,
    MatrixTable,
    Modal,
    MoneyInput,
    SelectInput,
    StatCard,
    TextareaInput,
} from '@/components/unity';
import type { MatrixColumn } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    MatrixRow,
    SavingsMatrix,
    SavingsMonthOption,
    SavingsRules,
} from '@/types/savings';

const props = defineProps<{
    matrix: SavingsMatrix | null;
    cycle: { id: number; name: string } | null;
    currentMonth: SavingsMonthOption | null;
    months: SavingsMonthOption[];
    members: {
        id: number;
        member_number: number;
        full_name: string;
        is_active: boolean;
    }[];
    rules: SavingsRules | null;
    filters?: { through: number | null };
    abilities: { record: boolean };
}>();

const depositOpen = ref(false);
const through = ref<string | number | null>(props.filters?.through ?? null);

const form = useForm({
    member_id: null as number | null,
    cycle_month_id: props.currentMonth?.id ?? null,
    amount_ngwee: null as number | null,
    note: '',
});

/** Two columns per month — Savings beside Interest — then the three summary columns. */
const columns = computed<MatrixColumn[]>(() => {
    if (!props.matrix) {
        return [];
    }

    const monthColumns = props.matrix.months.flatMap((month) => [
        {
            key: `${month.id}-savings`,
            label: month.label,
            sublabel: 'Savings',
            current: month.id === props.currentMonth?.id,
            muted: month.lockdown,
        },
        {
            key: `${month.id}-interest`,
            label: month.label,
            sublabel: 'Interest',
            current: month.id === props.currentMonth?.id,
            muted: month.lockdown,
        },
    ]);

    return [
        ...monthColumns,
        { key: 'total-savings', label: 'Total', sublabel: 'Savings' },
        { key: 'total-interest', label: 'Total', sublabel: 'Interest' },
    ];
});

const columnTotals = computed<Record<string, number>>(() => {
    if (!props.matrix) {
        return {};
    }

    const totals: Record<string, number> = {};

    for (const month of props.matrix.months) {
        const cell = props.matrix.totals.months[month.id];

        totals[`${month.id}-savings`] = cell?.savings ?? 0;
        totals[`${month.id}-interest`] = cell?.interest ?? 0;
    }

    totals['total-savings'] = props.matrix.totals.total_savings_ngwee;
    totals['total-interest'] = props.matrix.totals.total_interest_ngwee;

    return totals;
});

function cell(row: MatrixRow, column: MatrixColumn): number | null {
    if (column.key === 'total-savings') {
        return row.total_savings_ngwee;
    }

    if (column.key === 'total-interest') {
        return row.total_interest_ngwee;
    }

    const [monthId, kind] = column.key.split('-');
    const value = row.cells[Number(monthId)];

    if (!value) {
        return null;
    }

    const amount = kind === 'savings' ? value.savings : value.interest;

    // An untouched month reads as empty rather than K0.00, the way the workbook does.
    return amount === 0 ? null : amount;
}

const memberOptions = computed(() =>
    props.members
        .filter((member) => member.is_active)
        .map((member) => ({
            value: member.id,
            label: `${member.member_number}. ${member.full_name}`,
        })),
);

const monthOptions = computed(() =>
    props.months.map((month) => ({ value: month.id, label: month.label })),
);

const selectedMonth = computed(
    () =>
        props.months.find((month) => month.id === form.cycle_month_id) ?? null,
);

/** During lockdown the month has a ceiling, and it counts everything already saved. */
const cap = computed<number | null>(() =>
    selectedMonth.value?.lockdown
        ? (props.rules?.lockdown_cap_ngwee ?? null)
        : null,
);

const alreadySaved = computed<number>(() => {
    const row = props.matrix?.rows.find(
        (candidate) => candidate.member_id === form.member_id,
    );

    return row && form.cycle_month_id
        ? (row.cells[form.cycle_month_id]?.savings ?? 0)
        : 0;
});

const capWarning = computed<string | null>(() => {
    if (cap.value === null) {
        return null;
    }

    const remaining = cap.value - alreadySaved.value;

    return remaining <= 0
        ? `${selectedMonth.value?.label} is capped at ${formatMoney(cap.value)} and this member has already reached it.`
        : `${selectedMonth.value?.label} falls in the lockdown: at most ${formatMoney(remaining)} more may be saved.`;
});

function applyFilter(): void {
    router.get(
        '/app/savings',
        { through: through.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function submit(): void {
    form.post('/app/savings', {
        preserveScroll: true,
        onSuccess: () => {
            depositOpen.value = false;
            form.reset('amount_ngwee', 'note');
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Savings"
        heading="Savings ledger"
        :description="cycle ? `${cycle.name} cycle` : undefined"
    >
        <template #actions>
            <a
                :href="`/app/savings/export/xlsx${through ? `?through=${through}` : ''}`"
            >
                <AppButton variant="outline" size="sm">
                    <template #icon><Download class="size-4" /></template>
                    Excel
                </AppButton>
            </a>
            <a
                :href="`/app/savings/export/pdf${through ? `?through=${through}` : ''}`"
            >
                <AppButton variant="outline" size="sm">
                    <template #icon><Download class="size-4" /></template>
                    PDF
                </AppButton>
            </a>
            <AppButton
                v-if="abilities.record"
                size="sm"
                @click="depositOpen = true"
            >
                <template #icon><Plus class="size-4" /></template>
                Record savings
            </AppButton>
        </template>

        <AppCard v-if="!matrix">
            <EmptyState
                title="No active cycle"
                description="Seed or activate a cycle before recording savings."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Total savings"
                    :ngwee="matrix.totals.total_savings_ngwee"
                    :icon="PiggyBank"
                    accent="brand"
                    :hint="`${matrix.rows.length} members`"
                />
                <StatCard
                    label="Interest earned"
                    :ngwee="matrix.totals.total_interest_ngwee"
                    :icon="Coins"
                    hint="Shared from the group's lending"
                />
                <StatCard
                    label="Group wealth"
                    :ngwee="
                        matrix.totals.total_savings_ngwee +
                        matrix.totals.total_interest_ngwee
                    "
                    :icon="Wallet"
                    hint="Savings plus interest"
                />
                <StatCard
                    label="Net value"
                    :ngwee="matrix.totals.net_value_ngwee"
                    hint="After everything still owed"
                />
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <FormField label="Show months up to" class="w-56">
                    <SelectInput
                        v-model="through"
                        placeholder="The whole cycle"
                        :options="
                            months.map((month) => ({
                                value: month.sequence,
                                label: month.label,
                            }))
                        "
                        @change="applyFilter"
                    />
                </FormField>
            </div>

            <MatrixTable
                :rows="matrix.rows"
                :columns="columns"
                row-header="Member"
                :row-label="(row) => row.full_name"
                :row-sublabel="(row) => `#${row.member_number}`"
                :row-key="(row) => row.member_id"
                :cell="cell"
                :totals="columnTotals"
                :row-total="(row) => row.net_value_ngwee"
                row-total-label="Net value"
                class="max-h-[70vh]"
                @cell-click="
                    (row) => router.visit(`/app/savings/${row.member_id}`)
                "
            />

            <p class="text-xs text-muted-foreground">
                Amounts in Kwacha. Interest is each member's share of the
                group's pooled loan interest, split in proportion to cumulative
                savings. Select a row to open that member's statement.
            </p>
        </div>

        <ClientOnly>
            <Modal
                v-model:open="depositOpen"
                title="Record savings"
                description="Posted straight to the ledger. Corrections are made with a reversing entry, never an edit."
            >
                <form class="space-y-4" @submit.prevent="submit">
                    <FormField
                        label="Member"
                        required
                        :error="form.errors.member_id"
                    >
                        <SelectInput
                            v-model="form.member_id"
                            :options="memberOptions"
                            placeholder="Choose a member"
                            :invalid="!!form.errors.member_id"
                        />
                    </FormField>

                    <FormField
                        label="Month"
                        required
                        :error="form.errors.cycle_month_id"
                    >
                        <SelectInput
                            v-model="form.cycle_month_id"
                            :options="monthOptions"
                            placeholder="Choose a month"
                            :invalid="!!form.errors.cycle_month_id"
                        />
                    </FormField>

                    <FormField
                        label="Amount"
                        required
                        :error="form.errors.amount_ngwee"
                        :hint="
                            rules
                                ? `Minimum ${formatMoney(rules.minimum_ngwee)}, in steps of ${formatMoney(rules.increment_ngwee)}.`
                                : undefined
                        "
                    >
                        <MoneyInput
                            v-model="form.amount_ngwee"
                            :step="rules?.increment_ngwee ?? 0"
                            :min="rules?.minimum_ngwee"
                            :max="cap ?? undefined"
                            :invalid="!!form.errors.amount_ngwee"
                        />
                    </FormField>

                    <p
                        v-if="capWarning"
                        class="rounded-lg border border-gold-300 bg-gold-50 px-3 py-2 text-xs font-medium text-gold-900 dark:border-gold-400/30 dark:bg-gold-400/10 dark:text-gold-100"
                    >
                        {{ capWarning }}
                    </p>

                    <FormField label="Note" :error="form.errors.note">
                        <TextareaInput v-model="form.note" :rows="2" />
                    </FormField>
                </form>

                <template #footer>
                    <AppButton variant="ghost" @click="depositOpen = false">
                        Cancel
                    </AppButton>
                    <AppButton :loading="form.processing" @click="submit">
                        Record savings
                    </AppButton>
                </template>
            </Modal>
        </ClientOnly>
    </AdminLayout>
</template>
