<script setup lang="ts">
/**
 * The month's declarations, as the committee reads them at the table.
 *
 * Month-stepped rather than paginated by member: the question on the day is "who has
 * declared for this month, and who has not", so the missing members sit beside the
 * sheet as a panel to work through rather than an absence to be inferred.
 */
import { router, useForm } from '@inertiajs/vue3';
import {
    ClipboardList,
    Download,
    HandCoins,
    Plus,
    RotateCcw,
    TriangleAlert,
    UserX,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    DataTable,
    EmptyState,
    FormField,
    Modal,
    MoneyInput,
    SelectInput,
    StatCard,
    StatusBadge,
    TextareaInput,
    WindowCountdown,
} from '@/components/unity';
import type { Column } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    DeclarationMonth,
    DeclarationRules,
    DeclarationSheet,
    DeclarationSheetRow,
} from '@/types/declarations';

const props = defineProps<{
    cycle: { id: number; name: string } | null;
    month: DeclarationMonth | null;
    months: { id: number; sequence: number; label: string }[];
    sheet: DeclarationSheet | null;
    missing: {
        id: number;
        member_number: number;
        full_name: string;
        phone: string | null;
    }[];
    members: { id: number; member_number: number; full_name: string }[];
    rules: DeclarationRules | null;
    filters?: { month: number | null };
    abilities: { record: boolean; approve: boolean };
}>();

const recordOpen = ref(false);

const form = useForm({
    member_id: null as number | null,
    cycle_month_id: props.month?.id ?? null,
    saving_amount_ngwee: props.rules?.minimum_ngwee ?? null,
    loan_repayment_amount_ngwee: 0 as number | null,
    loan_requested_amount_ngwee: 0 as number | null,
    note: '',
});

const columns: Column<DeclarationSheetRow>[] = [
    { key: 'full_name', label: 'Member' },
    { key: 'saving_ngwee', label: 'Savings', numeric: true },
    {
        key: 'repayment_ngwee',
        label: 'Repayment',
        numeric: true,
        hideOnMobile: true,
    },
    {
        key: 'requested_ngwee',
        label: 'Loan requested',
        numeric: true,
        hideOnMobile: true,
    },
    { key: 'total_ngwee', label: 'Total expected', numeric: true },
    { key: 'status', label: 'Status' },
];

/** Declarations still waiting for the committee's ask, so nobody can pay them yet. */
const awaitingCount = computed<number>(
    () =>
        props.sheet?.rows.filter((row) => row.declared && !row.approved)
            .length ?? 0,
);

/**
 * The ask is offered on any declared row that is not yet approved and not yet through
 * its trading session — including a locked one, since a member turning up to pay on
 * the 5th still needs somebody to have accepted their figures.
 */
function canApprove(row: DeclarationSheetRow): boolean {
    return (
        props.abilities.approve &&
        row.declared &&
        !row.approved &&
        row.status !== 'processed'
    );
}

/** Handing it back is only possible before the month locks. */
function canReopen(row: DeclarationSheetRow): boolean {
    return props.abilities.approve && row.status === 'approved';
}

function approve(row: DeclarationSheetRow): void {
    router.post(
        `/app/declarations/${row.declaration_id}/approve`,
        {},
        { preserveScroll: true },
    );
}

function reopen(row: DeclarationSheetRow): void {
    router.delete(`/app/declarations/${row.declaration_id}/approve`, {
        preserveScroll: true,
    });
}

/** The declaration is capture-on-behalf, so the late flag is the treasurer's cue. */
const lateCount = computed<number>(
    () => props.sheet?.rows.filter((row) => row.is_late).length ?? 0,
);

const totalExpected = computed<number>(
    () =>
        (form.saving_amount_ngwee ?? 0) +
        (form.loan_repayment_amount_ngwee ?? 0) -
        (form.loan_requested_amount_ngwee ?? 0),
);

const exportQuery = computed<string>(() =>
    props.month ? `?month=${props.month.sequence}` : '',
);

function stepMonth(sequence: number | string | null): void {
    router.get(
        '/app/declarations',
        { month: sequence || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

/** Pre-fills the modal for one of the members still missing from the sheet. */
function recordFor(memberId: number | null = null): void {
    form.member_id = memberId;
    form.cycle_month_id = props.month?.id ?? null;
    form.saving_amount_ngwee = props.rules?.minimum_ngwee ?? null;
    form.loan_repayment_amount_ngwee = 0;
    form.loan_requested_amount_ngwee = 0;
    form.note = '';
    recordOpen.value = true;
}

function submit(): void {
    form.post('/app/declarations', {
        preserveScroll: true,
        onSuccess: () => {
            recordOpen.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Declarations"
        heading="Declarations"
        :description="month ? month.label : cycle?.name"
    >
        <template #actions>
            <a :href="`/app/declarations/export/xlsx${exportQuery}`">
                <AppButton variant="outline" size="sm">
                    <template #icon><Download class="size-4" /></template>
                    Excel
                </AppButton>
            </a>
            <a :href="`/app/declarations/export/pdf${exportQuery}`">
                <AppButton variant="outline" size="sm">
                    <template #icon><Download class="size-4" /></template>
                    PDF
                </AppButton>
            </a>
            <AppButton
                v-if="abilities.record"
                size="sm"
                @click="recordFor(null)"
            >
                <template #icon><Plus class="size-4" /></template>
                Record a declaration
            </AppButton>
        </template>

        <AppCard v-if="!sheet || !month">
            <EmptyState
                title="No active cycle"
                description="Seed or activate a cycle before capturing declarations."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <WindowCountdown
                :window="month.window"
                :seconds-remaining="month.seconds_remaining"
            />

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Declared"
                    :value="`${sheet.declared_count} / ${sheet.rows.length}`"
                    :icon="ClipboardList"
                    accent="brand"
                    :hint="
                        awaitingCount > 0
                            ? `${awaitingCount} awaiting approval`
                            : 'All approved'
                    "
                />
                <StatCard
                    label="Expected at the table"
                    :ngwee="
                        sheet.totals.saving_ngwee + sheet.totals.repayment_ngwee
                    "
                    hint="Savings plus repayments"
                />
                <StatCard
                    label="Loans requested"
                    :ngwee="sheet.totals.requested_ngwee"
                    :icon="HandCoins"
                    accent="gold"
                    hint="What the fund must pay out"
                />
                <StatCard
                    label="Late declarations"
                    :value="lateCount"
                    :icon="TriangleAlert"
                    hint="Captured after the 3rd"
                />
            </div>

            <div class="flex flex-wrap items-end gap-3">
                <FormField label="Month" class="w-56">
                    <SelectInput
                        :model-value="month.sequence"
                        :options="
                            months.map((row) => ({
                                value: row.sequence,
                                label: row.label,
                            }))
                        "
                        @update:model-value="stepMonth"
                    />
                </FormField>
            </div>

            <div class="grid gap-5 xl:grid-cols-[2fr_1fr]">
                <AppCard :title="`${month.label} declarations`" flush>
                    <DataTable
                        :rows="sheet.rows"
                        :columns="columns"
                        :row-key="(row) => row.member_id"
                        empty-title="Nothing declared yet"
                        empty-description="Declarations appear here as members submit them."
                    >
                        <template #cell-full_name="{ row }">
                            <span class="block font-medium">{{
                                row.full_name
                            }}</span>
                            <span class="block text-xs text-muted-foreground"
                                >#{{ row.member_number }}</span
                            >
                        </template>

                        <template #cell-saving_ngwee="{ row }">
                            <span v-if="row.declared" class="tabular">{{
                                formatMoney(row.saving_ngwee)
                            }}</span>
                            <span v-else class="text-muted-foreground">—</span>
                        </template>

                        <template #cell-repayment_ngwee="{ row }">
                            <span v-if="row.declared" class="tabular">{{
                                formatMoney(row.repayment_ngwee)
                            }}</span>
                            <span v-else class="text-muted-foreground">—</span>
                        </template>

                        <template #cell-requested_ngwee="{ row }">
                            <span v-if="row.declared" class="tabular">{{
                                formatMoney(row.requested_ngwee)
                            }}</span>
                            <span v-else class="text-muted-foreground">—</span>
                        </template>

                        <!-- A negative total is the fund paying the member out on the
                             day; it is shown as such, never clamped to zero. -->
                        <template #cell-total_ngwee="{ row }">
                            <span
                                v-if="row.declared"
                                class="tabular font-semibold"
                                :class="
                                    row.total_ngwee < 0
                                        ? 'text-destructive'
                                        : ''
                                "
                                >{{ formatMoney(row.total_ngwee) }}</span
                            >
                            <span v-else class="text-muted-foreground">—</span>
                        </template>

                        <template #cell-status="{ row }">
                            <span class="flex items-center gap-1.5">
                                <StatusBadge
                                    v-if="row.status"
                                    :status="row.status"
                                    :label="row.status_label"
                                    size="sm"
                                />
                                <StatusBadge
                                    v-else
                                    status="pending"
                                    label="Not declared"
                                    tone="neutral"
                                    size="sm"
                                />
                                <StatusBadge
                                    v-if="row.is_late"
                                    status="late"
                                    label="Late"
                                    size="sm"
                                />
                            </span>
                        </template>

                        <!-- The ask. Approving accepts the figures: the member can no
                             longer edit them and either side may start the payment. -->
                        <template #actions="{ row }">
                            <AppButton
                                v-if="canApprove(row)"
                                size="sm"
                                @click="approve(row)"
                            >
                                Ask
                            </AppButton>
                            <AppButton
                                v-else-if="canReopen(row)"
                                variant="outline"
                                size="sm"
                                @click="reopen(row)"
                            >
                                <template #icon
                                    ><RotateCcw class="size-4"
                                /></template>
                                Reopen
                            </AppButton>
                        </template>
                    </DataTable>

                    <div
                        class="flex flex-wrap justify-end gap-6 border-t border-border px-5 py-3 text-sm"
                    >
                        <span class="text-muted-foreground"
                            >Savings
                            <span
                                class="tabular font-semibold text-foreground"
                                >{{
                                    formatMoney(sheet.totals.saving_ngwee)
                                }}</span
                            ></span
                        >
                        <span class="text-muted-foreground"
                            >Repayments
                            <span
                                class="tabular font-semibold text-foreground"
                                >{{
                                    formatMoney(sheet.totals.repayment_ngwee)
                                }}</span
                            ></span
                        >
                        <span class="text-muted-foreground"
                            >Requested
                            <span
                                class="tabular font-semibold text-foreground"
                                >{{
                                    formatMoney(sheet.totals.requested_ngwee)
                                }}</span
                            ></span
                        >
                        <span class="text-muted-foreground"
                            >Total expected
                            <span
                                class="tabular font-semibold"
                                :class="
                                    sheet.totals.total_ngwee < 0
                                        ? 'text-destructive'
                                        : 'text-foreground'
                                "
                                >{{
                                    formatMoney(sheet.totals.total_ngwee)
                                }}</span
                            ></span
                        >
                    </div>
                </AppCard>

                <AppCard
                    title="Still to declare"
                    :description="`${missing.length} active member${missing.length === 1 ? '' : 's'} have not been captured.`"
                    flush
                >
                    <div v-if="missing.length === 0" class="p-5">
                        <EmptyState
                            title="Everybody has declared"
                            description="Every active member is on the sheet for this month."
                        />
                    </div>

                    <ul v-else class="divide-y divide-border">
                        <li
                            v-for="member in missing"
                            :key="member.id"
                            class="flex items-center gap-3 px-5 py-3"
                        >
                            <UserX
                                class="size-4 shrink-0 text-muted-foreground"
                            />

                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ member.full_name }}</span
                                >
                                <span
                                    class="block text-xs text-muted-foreground"
                                    >#{{ member.member_number }}
                                    {{
                                        member.phone ? `· ${member.phone}` : ''
                                    }}</span
                                >
                            </span>

                            <AppButton
                                v-if="abilities.record"
                                size="sm"
                                variant="outline"
                                @click="recordFor(member.id)"
                            >
                                Capture
                            </AppButton>
                        </li>
                    </ul>
                </AppCard>
            </div>
        </div>

        <ClientOnly>
            <Modal
                :open="recordOpen"
                title="Record a declaration"
                description="Captured on a member's behalf. Anything after the 3rd is stamped late automatically."
                @close="recordOpen = false"
            >
                <div class="space-y-4">
                    <FormField
                        label="Member"
                        :error="form.errors.member_id"
                        required
                    >
                        <SelectInput
                            v-model="form.member_id"
                            placeholder="Choose a member"
                            :options="
                                members.map((member) => ({
                                    value: member.id,
                                    label: `${member.member_number}. ${member.full_name}`,
                                }))
                            "
                        />
                    </FormField>

                    <FormField
                        label="Month"
                        :error="form.errors.cycle_month_id"
                        required
                    >
                        <SelectInput
                            v-model="form.cycle_month_id"
                            :options="
                                months.map((row) => ({
                                    value: row.id,
                                    label: row.label,
                                }))
                            "
                        />
                    </FormField>

                    <FormField
                        label="Monthly savings"
                        :error="form.errors.saving_amount_ngwee"
                        :hint="`In steps of ${formatMoney(rules?.increment_ngwee ?? 50000)}.`"
                        required
                    >
                        <MoneyInput
                            v-model="form.saving_amount_ngwee"
                            :step="rules?.increment_ngwee ?? 50000"
                            :invalid="!!form.errors.saving_amount_ngwee"
                        />
                    </FormField>

                    <FormField
                        label="Loan repayment"
                        :error="form.errors.loan_repayment_amount_ngwee"
                    >
                        <MoneyInput
                            v-model="form.loan_repayment_amount_ngwee"
                            :invalid="!!form.errors.loan_repayment_amount_ngwee"
                        />
                    </FormField>

                    <FormField
                        label="New loan requested"
                        :error="form.errors.loan_requested_amount_ngwee"
                    >
                        <MoneyInput
                            v-model="form.loan_requested_amount_ngwee"
                            :invalid="!!form.errors.loan_requested_amount_ngwee"
                        />
                    </FormField>

                    <div
                        class="flex items-center justify-between gap-3 rounded-lg border border-border bg-muted px-4 py-3"
                    >
                        <span class="text-sm font-medium"
                            >Total expected payment</span
                        >
                        <span
                            class="tabular text-base font-semibold"
                            :class="totalExpected < 0 ? 'text-destructive' : ''"
                            >{{ formatMoney(totalExpected) }}</span
                        >
                    </div>

                    <FormField label="Note" :error="form.errors.note">
                        <TextareaInput
                            v-model="form.note"
                            :rows="2"
                            placeholder="Declared by phone to the treasurer on the 2nd."
                        />
                    </FormField>
                </div>

                <template #footer>
                    <AppButton variant="ghost" @click="recordOpen = false">
                        Cancel
                    </AppButton>
                    <AppButton :loading="form.processing" @click="submit">
                        Record
                    </AppButton>
                </template>
            </Modal>
        </ClientOnly>
    </AdminLayout>
</template>
