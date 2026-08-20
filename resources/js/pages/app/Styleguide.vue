<script setup lang="ts">
/**
 * The style tile and component gallery for the Unity design system.
 *
 * This renders the real components, not screenshots, so it doubles as a smoke
 * test: if something here looks wrong, the modules built on it are wrong too.
 */
import { Banknote, HandCoins, PiggyBank, Users } from '@lucide/vue';
import { ref } from 'vue';

import {
    AppButton,
    AppCard,
    Can,
    ConfirmDialog,
    DataTable,
    EmptyState,
    FormField,
    MatrixTable,
    Modal,
    MoneyInput,
    MoneyText,
    StatCard,
    StatusBadge,
    Stepper,
    TextInput,
} from '@/components/unity';
import type { Column, MatrixColumn } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';

defineProps<{
    roles: {
        value: string;
        label: string;
        is_committee: boolean;
        permissions: string[];
    }[];
    permissions: { value: string; label: string; group: string }[];
}>();

/* ---- Palette -------------------------------------------------------------- */

const brandScale = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
const goldScale = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900];

const typeScale = [
    {
        name: 'Display',
        class: 'text-3xl font-semibold tracking-tight',
        sample: 'K1,482,500.00',
    },
    {
        name: 'Heading',
        class: 'text-xl font-semibold tracking-tight',
        sample: 'Savings ledger',
    },
    {
        name: 'Subheading',
        class: 'text-base font-semibold',
        sample: 'August 2026',
    },
    {
        name: 'Body',
        class: 'text-sm',
        sample: 'Declarations close at the end of the 3rd.',
    },
    {
        name: 'Caption',
        class: 'text-xs text-muted-foreground',
        sample: 'Recorded by the treasurer',
    },
    {
        name: 'Overline',
        class: 'text-xs font-semibold uppercase tracking-wide text-muted-foreground',
        sample: 'Total savings',
    },
];

const spacing = [1, 2, 3, 4, 5, 6, 8, 12];

/* ---- Interactive demos ---------------------------------------------------- */

const savings = ref<number | null>(150_000);
const freeAmount = ref<number | null>(null);
const memberName = ref('Bertha Chileshe');

const modalOpen = ref(false);
const confirmOpen = ref(false);
const dualOpen = ref(false);

const step = ref(1);
const wizardSteps = [
    { key: 'details', label: 'Details' },
    { key: 'savings', label: 'Savings' },
    { key: 'review', label: 'Review' },
    { key: 'done', label: 'Done' },
];

/* ---- Table demo ----------------------------------------------------------- */

type DemoRow = {
    id: number;
    member: string;
    number: number;
    savings: number;
    status: string;
    abilities: { edit: boolean; reverse: boolean };
};

const demoRows: DemoRow[] = [
    {
        id: 1,
        member: 'Bertha Chileshe',
        number: 2,
        savings: 450_000,
        status: 'active',
        abilities: { edit: true, reverse: false },
    },
    {
        id: 2,
        member: 'Gloria Kangwa',
        number: 6,
        savings: 1_250_000,
        status: 'active',
        abilities: { edit: true, reverse: true },
    },
    {
        id: 3,
        member: 'Ireen Seta',
        number: 7,
        savings: 300_000,
        status: 'pending',
        abilities: { edit: false, reverse: false },
    },
    {
        id: 4,
        member: 'Mirriam Lungu',
        number: 13,
        savings: 2_100_000,
        status: 'active',
        abilities: { edit: true, reverse: true },
    },
];

const demoColumns: Column<DemoRow>[] = [
    { key: 'member', label: 'Member', sortable: true },
    {
        key: 'number',
        label: 'No.',
        numeric: true,
        hideOnMobile: true,
        width: '5rem',
    },
    { key: 'savings', label: 'Savings', numeric: true, sortable: true },
    { key: 'status', label: 'Status', align: 'center' },
];

/* ---- Matrix demo ---------------------------------------------------------- */

const matrixColumns: MatrixColumn[] = [
    { key: 'dec', label: 'Dec', sublabel: '2025' },
    { key: 'jan', label: 'Jan', sublabel: '2026' },
    { key: 'feb', label: 'Feb', sublabel: '2026' },
    { key: 'mar', label: 'Mar', sublabel: '2026' },
    { key: 'apr', label: 'Apr', sublabel: '2026' },
    { key: 'may', label: 'May', sublabel: '2026' },
    { key: 'jun', label: 'Jun', sublabel: '2026' },
    { key: 'jul', label: 'Jul', sublabel: '2026' },
    { key: 'aug', label: 'Aug', sublabel: '2026', current: true },
    { key: 'sep', label: 'Sep', sublabel: '2026', muted: true },
];

type MatrixRow = {
    id: number;
    name: string;
    number: number;
    values: Record<string, number | null>;
};

const matrixRows: MatrixRow[] = demoRows.map((row, index) => ({
    id: row.id,
    name: row.member,
    number: row.number,
    values: Object.fromEntries(
        matrixColumns.map((column, position) => [
            column.key,
            position > 8 - index
                ? null
                : 50_000 * (index + 1) + position * 25_000,
        ]),
    ),
}));

const matrixTotals = Object.fromEntries(
    matrixColumns.map((column) => [
        column.key,
        matrixRows.reduce((sum, row) => sum + (row.values[column.key] ?? 0), 0),
    ]),
);

function rowTotal(row: MatrixRow): number {
    return Object.values(row.values).reduce<number>(
        (sum, value) => sum + (value ?? 0),
        0,
    );
}

const statuses = [
    'active',
    'pending',
    'approved',
    'rejected',
    'defaulted',
    'draft',
    'closed',
    'trading',
];
</script>

<template>
    <AdminLayout
        title="Styleguide"
        heading="Design system"
        description="The Unity component library and style tile"
    >
        <div class="space-y-8">
            <!-- ===================== STYLE TILE ===================== -->
            <AppCard
                title="Palette"
                description="Deep green carries actions and identity; warm gold marks value, money and the single most important figure on a screen."
            >
                <div class="space-y-6">
                    <div>
                        <p
                            class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Brand — deep green
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <div
                                v-for="shade in brandScale"
                                :key="shade"
                                class="min-w-16 flex-1"
                            >
                                <div
                                    class="h-14 rounded-md border border-border/50"
                                    :style="{
                                        backgroundColor: `var(--brand-${shade})`,
                                    }"
                                />
                                <p
                                    class="tabular mt-1 text-center text-[0.625rem] text-muted-foreground"
                                >
                                    {{ shade }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <p
                            class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Accent — warm gold
                        </p>
                        <div class="flex flex-wrap gap-1">
                            <div
                                v-for="shade in goldScale"
                                :key="shade"
                                class="min-w-16 flex-1"
                            >
                                <div
                                    class="h-14 rounded-md border border-border/50"
                                    :style="{
                                        backgroundColor: `var(--gold-${shade})`,
                                    }"
                                />
                                <p
                                    class="tabular mt-1 text-center text-[0.625rem] text-muted-foreground"
                                >
                                    {{ shade }}
                                </p>
                            </div>
                        </div>
                    </div>

                    <div>
                        <p
                            class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                        >
                            Semantic
                        </p>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <div
                                v-for="tone in [
                                    'background',
                                    'card',
                                    'muted',
                                    'border',
                                ]"
                                :key="tone"
                            >
                                <div
                                    class="h-14 rounded-md border border-border"
                                    :style="{
                                        backgroundColor: `var(--${tone})`,
                                    }"
                                />
                                <p
                                    class="mt-1 text-center text-[0.625rem] text-muted-foreground"
                                >
                                    {{ tone }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </AppCard>

            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard
                    title="Type scale"
                    description="Money always renders in tabular figures so columns align."
                >
                    <dl class="space-y-4">
                        <div
                            v-for="entry in typeScale"
                            :key="entry.name"
                            class="flex items-baseline gap-4"
                        >
                            <dt
                                class="w-24 shrink-0 text-xs text-muted-foreground"
                            >
                                {{ entry.name }}
                            </dt>
                            <dd
                                :class="[
                                    entry.class,
                                    'tabular min-w-0 truncate',
                                ]"
                            >
                                {{ entry.sample }}
                            </dd>
                        </div>
                    </dl>
                </AppCard>

                <AppCard
                    title="Spacing"
                    description="A 4px base step, used through Tailwind's scale."
                >
                    <div class="space-y-2">
                        <div
                            v-for="unit in spacing"
                            :key="unit"
                            class="flex items-center gap-3"
                        >
                            <span
                                class="tabular w-10 shrink-0 text-xs text-muted-foreground"
                                >{{ unit }}</span
                            >
                            <div
                                class="h-3 rounded bg-brand-500"
                                :style="{ width: `${unit * 4}px` }"
                            />
                            <span class="tabular text-xs text-muted-foreground"
                                >{{ unit * 4 }}px</span
                            >
                        </div>
                    </div>
                </AppCard>
            </div>

            <!-- ===================== STAT CARDS ===================== -->
            <section>
                <h2
                    class="mb-3 text-sm font-semibold tracking-tight text-foreground"
                >
                    StatCard
                </h2>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="Total savings"
                        :ngwee="148_250_000"
                        :icon="PiggyBank"
                        accent="brand"
                        :trend="12.4"
                        trend-label="vs last month"
                    />
                    <StatCard
                        label="This month"
                        :ngwee="12_500_000"
                        :icon="Banknote"
                        hint="Recorded so far"
                    />
                    <StatCard
                        label="Loans out"
                        :ngwee="86_400_000"
                        :icon="HandCoins"
                        accent="gold"
                        :trend="-3.2"
                        trend-label="vs last month"
                    />
                    <StatCard
                        label="Members"
                        value="30"
                        :icon="Users"
                        hint="Active this cycle"
                    />
                </div>
            </section>

            <!-- ===================== BUTTONS ===================== -->
            <AppCard
                title="AppButton"
                description="Variants map to intent, not colour."
            >
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <AppButton variant="primary">Primary</AppButton>
                        <AppButton variant="gold">Gold</AppButton>
                        <AppButton variant="secondary">Secondary</AppButton>
                        <AppButton variant="outline">Outline</AppButton>
                        <AppButton variant="ghost">Ghost</AppButton>
                        <AppButton variant="destructive">Destructive</AppButton>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <AppButton size="sm">Small</AppButton>
                        <AppButton size="md">Medium</AppButton>
                        <AppButton size="lg">Large</AppButton>
                        <AppButton loading>Loading</AppButton>
                        <AppButton disabled>Disabled</AppButton>
                    </div>
                </div>
            </AppCard>

            <!-- ===================== MONEY ===================== -->
            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard
                    title="MoneyInput"
                    description="Types Kwacha, emits ngwee. Snaps to the step on blur."
                >
                    <div class="space-y-4">
                        <FormField label="Monthly savings" required>
                            <template #default="{ id }">
                                <MoneyInput
                                    :id="id"
                                    v-model="savings"
                                    :step="50_000"
                                    :min="50_000"
                                />
                            </template>
                        </FormField>
                        <p class="text-xs text-muted-foreground">
                            Emitted value:
                            <code
                                class="tabular rounded bg-muted px-1.5 py-0.5 font-medium"
                                >{{ savings ?? 'null' }}</code
                            >
                            ngwee — displays as
                            <MoneyText
                                :ngwee="savings ?? 0"
                                class="font-medium"
                            />
                        </p>

                        <FormField label="Any amount" hint="No step enforced.">
                            <template #default="{ id }">
                                <MoneyInput
                                    :id="id"
                                    v-model="freeAmount"
                                    :steppers="false"
                                />
                            </template>
                        </FormField>

                        <FormField
                            label="With a server error"
                            error="The amount must be at least K500.00."
                        >
                            <template #default="{ id, invalid }">
                                <MoneyInput
                                    :id="id"
                                    :model-value="12_345"
                                    :invalid="invalid"
                                />
                            </template>
                        </FormField>
                    </div>
                </AppCard>

                <AppCard
                    title="MoneyText & FormField"
                    description="Tabular figures everywhere money appears."
                >
                    <div class="space-y-4">
                        <dl class="space-y-2 text-sm">
                            <div
                                class="flex justify-between border-b border-border pb-2"
                            >
                                <dt class="text-muted-foreground">Standard</dt>
                                <dd><MoneyText :ngwee="148_250_000" /></dd>
                            </div>
                            <div
                                class="flex justify-between border-b border-border pb-2"
                            >
                                <dt class="text-muted-foreground">Compact</dt>
                                <dd>
                                    <MoneyText :ngwee="148_250_000" compact />
                                </dd>
                            </div>
                            <div
                                class="flex justify-between border-b border-border pb-2"
                            >
                                <dt class="text-muted-foreground">
                                    Signed, positive
                                </dt>
                                <dd><MoneyText :ngwee="42_500" signed /></dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-muted-foreground">
                                    Signed, negative
                                </dt>
                                <dd><MoneyText :ngwee="-18_300" signed /></dd>
                            </div>
                        </dl>

                        <FormField
                            label="Member name"
                            hint="A plain text field for comparison."
                        >
                            <template #default="{ id, invalid }">
                                <TextInput
                                    :id="id"
                                    v-model="memberName"
                                    :invalid="invalid"
                                />
                            </template>
                        </FormField>
                    </div>
                </AppCard>
            </div>

            <!-- ===================== BADGES ===================== -->
            <AppCard
                title="StatusBadge"
                description="Colour is inferred from the enum value, so a status reads the same everywhere."
            >
                <div class="flex flex-wrap gap-2">
                    <StatusBadge
                        v-for="status in statuses"
                        :key="status"
                        :status="status"
                    />
                </div>
            </AppCard>

            <!-- ===================== DATA TABLE ===================== -->
            <section>
                <h2
                    class="mb-3 text-sm font-semibold tracking-tight text-foreground"
                >
                    DataTable
                </h2>
                <p class="mb-3 text-xs text-muted-foreground">
                    Row actions render from each row's server-computed abilities
                    — "Ireen Seta" has none, so hers are hidden rather than
                    disabled.
                </p>
                <DataTable
                    :rows="demoRows"
                    :columns="demoColumns"
                    searchable
                    exportable
                    :sort="{ column: 'member', direction: 'asc' }"
                    :meta="{
                        current_page: 1,
                        last_page: 3,
                        per_page: 4,
                        total: 12,
                        from: 1,
                        to: 4,
                    }"
                >
                    <template #cell-savings="{ value }">
                        <MoneyText :ngwee="value as number" />
                    </template>
                    <template #cell-status="{ value }">
                        <StatusBadge :status="value as string" size="sm" />
                    </template>
                    <template #actions="{ row }">
                        <div class="flex justify-end gap-1">
                            <AppButton
                                v-if="row.abilities.edit"
                                variant="ghost"
                                size="sm"
                                >Edit</AppButton
                            >
                            <AppButton
                                v-if="row.abilities.reverse"
                                variant="ghost"
                                size="sm"
                                >Reverse</AppButton
                            >
                            <span
                                v-if="
                                    !row.abilities.edit &&
                                    !row.abilities.reverse
                                "
                                class="text-xs text-muted-foreground"
                            >
                                —
                            </span>
                        </div>
                    </template>
                </DataTable>
            </section>

            <!-- ===================== MATRIX TABLE ===================== -->
            <section>
                <h2
                    class="mb-3 text-sm font-semibold tracking-tight text-foreground"
                >
                    MatrixTable
                </h2>
                <p class="mb-3 text-xs text-muted-foreground">
                    The workbook view. Scroll sideways — the member column, the
                    header row and the totals row all stay pinned.
                </p>
                <MatrixTable
                    :rows="matrixRows"
                    :columns="matrixColumns"
                    :row-key="(row) => row.id"
                    :row-label="(row) => row.name"
                    :row-sublabel="(row) => `No. ${row.number}`"
                    :cell="(row, column) => row.values[column.key] ?? null"
                    :totals="matrixTotals"
                    :row-total="rowTotal"
                    class="max-h-96"
                />
            </section>

            <!-- ===================== OVERLAYS ===================== -->
            <div class="grid gap-4 lg:grid-cols-2">
                <AppCard
                    title="Modal & ConfirmDialog"
                    description="The dual-approval variant enforces the two-person rule."
                >
                    <div class="flex flex-wrap gap-2">
                        <AppButton variant="outline" @click="modalOpen = true"
                            >Open modal</AppButton
                        >
                        <AppButton
                            variant="destructive"
                            @click="confirmOpen = true"
                            >Destructive confirm</AppButton
                        >
                        <AppButton variant="gold" @click="dualOpen = true"
                            >Dual approval</AppButton
                        >
                    </div>
                </AppCard>

                <AppCard
                    title="Stepper"
                    description="Used by the registration and share-out wizards."
                >
                    <div class="space-y-4">
                        <Stepper :steps="wizardSteps" :current="step" />
                        <div class="flex justify-between gap-2">
                            <AppButton
                                variant="ghost"
                                size="sm"
                                :disabled="step === 0"
                                @click="step--"
                                >Back</AppButton
                            >
                            <AppButton
                                size="sm"
                                :disabled="step === wizardSteps.length - 1"
                                @click="step++"
                            >
                                Next
                            </AppButton>
                        </div>
                    </div>
                </AppCard>
            </div>

            <!-- ===================== EMPTY STATE ===================== -->
            <AppCard title="EmptyState" flush>
                <EmptyState
                    title="No declarations yet"
                    description="Declarations for this month open on the 1st at 08:00."
                >
                    <template #action>
                        <AppButton size="sm">Open declarations</AppButton>
                    </template>
                </EmptyState>
            </AppCard>

            <!-- ===================== ROLE MATRIX ===================== -->
            <AppCard
                title="Roles & permissions"
                description="Generated from App\Enums\MemberRole and App\Enums\Permission — permissions are the currency, roles are just bundles."
                flush
            >
                <div class="scrollbar-thin overflow-x-auto">
                    <table class="w-full border-collapse text-sm">
                        <thead>
                            <tr class="border-b border-border bg-muted/50">
                                <th
                                    class="sticky left-0 z-10 bg-muted/50 px-4 py-3 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    Permission
                                </th>
                                <th
                                    v-for="role in roles"
                                    :key="role.value"
                                    class="px-3 py-3 text-center text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ role.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="permission in permissions"
                                :key="permission.value"
                                class="border-b border-border/70 last:border-0 hover:bg-accent/40"
                            >
                                <td
                                    class="sticky left-0 z-10 bg-card px-4 py-2.5"
                                >
                                    <span
                                        class="block font-medium text-card-foreground"
                                        >{{ permission.label }}</span
                                    >
                                    <code
                                        class="block text-[0.6875rem] text-muted-foreground"
                                        >{{ permission.value }}</code
                                    >
                                </td>
                                <td
                                    v-for="role in roles"
                                    :key="role.value"
                                    class="px-3 py-2.5 text-center"
                                >
                                    <span
                                        v-if="
                                            role.permissions.includes(
                                                permission.value,
                                            )
                                        "
                                        class="inline-block size-2 rounded-full bg-brand-600"
                                        :aria-label="`${role.label} has ${permission.value}`"
                                    />
                                    <span
                                        v-else
                                        class="text-muted-foreground/30"
                                        aria-hidden="true"
                                        >·</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>

            <!-- ===================== CAN ===================== -->
            <AppCard
                title="&lt;Can&gt;"
                description="Renders by permission. This reflects your real permissions right now."
            >
                <div class="space-y-2 text-sm">
                    <Can permission="loans.approve">
                        <p class="text-brand-700 dark:text-brand-300">
                            ✓ You can approve loans.
                        </p>
                        <template #fallback>
                            <p class="text-muted-foreground">
                                ✗ You cannot approve loans.
                            </p>
                        </template>
                    </Can>
                    <Can :any="['payouts.execute', 'payouts.approve']">
                        <p class="text-brand-700 dark:text-brand-300">
                            ✓ You have some payout access.
                        </p>
                        <template #fallback>
                            <p class="text-muted-foreground">
                                ✗ You have no payout access.
                            </p>
                        </template>
                    </Can>
                </div>
            </AppCard>
        </div>

        <!-- ===================== OVERLAY INSTANCES ===================== -->
        <Modal
            v-model:open="modalOpen"
            title="Record a savings payment"
            description="August 2026"
        >
            <FormField label="Amount" hint="Savings move in K500 steps.">
                <template #default="{ id }">
                    <MoneyInput
                        :id="id"
                        :model-value="150_000"
                        :step="50_000"
                    />
                </template>
            </FormField>
            <template #footer>
                <AppButton variant="ghost" @click="modalOpen = false"
                    >Cancel</AppButton
                >
                <AppButton @click="modalOpen = false">Record payment</AppButton>
            </template>
        </Modal>

        <ConfirmDialog
            v-model:open="confirmOpen"
            variant="destructive"
            title="Reverse this transaction?"
            :message="`This posts a reversing entry of ${formatMoney(150_000)}. The original record stays in the ledger — nothing is deleted.`"
            confirm-label="Post reversal"
            @confirm="confirmOpen = false"
        />

        <ConfirmDialog
            v-model:open="dualOpen"
            variant="dual-approval"
            title="Disburse loan"
            message="Disbursing a loan needs two committee members."
            :action-summary="`Disburse ${formatMoney(1_200_000)} to Bertha Chileshe`"
            confirm-label="Disburse"
            @confirm="dualOpen = false"
        />
    </AdminLayout>
</template>
