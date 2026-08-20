<script setup lang="ts">
/**
 * The Social Fund dashboard: what the fund holds, how it moved month by month, who
 * still owes their K250, and the last ten entries.
 *
 * Every figure arrives computed from SocialFundOverview — this screen formats, it
 * never adds up, so the dashboard and the exported sheet cannot disagree.
 */
import { Link, useForm } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowUpRight,
    HeartHandshake,
    Plus,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    BarChart,
    Can,
    ClientOnly,
    EmptyState,
    FormField,
    Modal,
    MoneyInput,
    SelectInput,
    StatCard,
    TextareaInput,
} from '@/components/unity';
import type { BarChartPoint } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    FundEntry,
    FundOverview,
    FundRules,
    UnpaidContribution,
} from '@/types/fund';

const props = defineProps<{
    overview: FundOverview | null;
    unpaid: UnpaidContribution[];
    recent: FundEntry[];
    openClaims: number;
    pendingTransfers: number;
    rules: FundRules | null;
    abilities: { record: boolean; approveOutflow: boolean; apportion: boolean };
}>();

const contributionOpen = ref(false);

const form = useForm({
    member_id: null as number | null,
    amount_ngwee: props.rules?.contribution_ngwee ?? null,
    occurred_on: new Date().toISOString().slice(0, 10),
    note: '',
});

const points = computed<BarChartPoint[]>(
    () =>
        props.overview?.months.map((month) => ({
            label: month.short_label,
            primary: month.in_ngwee,
            secondary: month.out_ngwee,
        })) ?? [],
);

const unpaidOptions = computed(() =>
    props.unpaid.map((member) => ({
        value: member.member_id,
        label: `${member.member_number}. ${member.full_name}`,
    })),
);

/** How far through the once-per-cycle contribution the group is. */
const collected = computed(() => {
    if (!props.overview || props.overview.contributions_expected === 0) {
        return 0;
    }

    return Math.round(
        (props.overview.contributions_paid /
            props.overview.contributions_expected) *
            100,
    );
});

function openFor(memberId: number): void {
    form.member_id = memberId;
    form.amount_ngwee = props.rules?.contribution_ngwee ?? null;
    contributionOpen.value = true;
}

function submit(): void {
    form.post('/app/fund/contributions', {
        preserveScroll: true,
        onSuccess: () => {
            contributionOpen.value = false;
            form.reset('member_id', 'note');
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Social fund"
        heading="Social fund"
        description="The group's own money for its bereavements, births and gatherings. Every outflow carries two signatures."
    >
        <div v-if="!overview" class="py-10">
            <EmptyState
                title="No active cycle"
                description="Activate a cycle before recording anything into the fund."
                :icon="HeartHandshake"
            />
        </div>

        <div v-else class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Fund balance"
                    :ngwee="overview.balance_ngwee"
                    :icon="Wallet"
                    accent="gold"
                    hint="What the fund holds today"
                />
                <StatCard
                    label="Received"
                    :ngwee="overview.inflow_ngwee"
                    :icon="ArrowUpRight"
                    hint="Contributions and late penalties"
                />
                <StatCard
                    label="Paid out"
                    :ngwee="overview.outflow_ngwee"
                    :icon="ArrowDownRight"
                    hint="Grants, gatherings and apportionments"
                />
                <StatCard
                    label="Contributions in"
                    :value="`${overview.contributions_paid} / ${overview.contributions_expected}`"
                    :icon="Users"
                    :hint="`${collected}% of the group has paid`"
                />
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <AppCard
                    class="lg:col-span-2"
                    title="Month by month"
                    description="What came in against what went out, on one scale."
                >
                    <BarChart
                        :points="points"
                        primary-label="In"
                        secondary-label="Out"
                    />
                </AppCard>

                <AppCard
                    title="Still to pay"
                    :description="
                        rules
                            ? `${formatMoney(rules.contribution_ngwee)} once per cycle, in full.`
                            : undefined
                    "
                    flush
                >
                    <div v-if="unpaid.length === 0" class="p-5">
                        <EmptyState
                            title="Everyone has paid"
                            description="Every active member's contribution is in."
                        />
                    </div>

                    <ul
                        v-else
                        class="max-h-72 divide-y divide-border overflow-y-auto"
                    >
                        <li
                            v-for="member in unpaid"
                            :key="member.member_id"
                            class="flex items-center gap-3 px-5 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ member.member_number }}.
                                    {{ member.full_name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ member.phone ?? 'No phone on file' }}
                                    <span v-if="member.is_diaspora">
                                        · diaspora</span
                                    >
                                </p>
                            </div>

                            <Can permission="fund.record">
                                <AppButton
                                    size="sm"
                                    variant="outline"
                                    @click="openFor(member.member_id)"
                                >
                                    <Plus class="size-3.5" />
                                    Record
                                </AppButton>
                            </Can>
                        </li>
                    </ul>
                </AppCard>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <AppCard
                    class="lg:col-span-2"
                    title="Recent entries"
                    description="The last ten movements on the fund's ledger."
                    flush
                >
                    <div v-if="recent.length === 0" class="p-5">
                        <EmptyState
                            title="Nothing recorded yet"
                            description="Contributions, penalties and grants all appear here."
                        />
                    </div>

                    <ul v-else class="divide-y divide-border">
                        <li
                            v-for="entry in recent"
                            :key="entry.id"
                            class="flex items-center gap-4 px-5 py-3"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">
                                    {{ entry.type_label }}
                                    <span
                                        v-if="entry.member"
                                        class="font-normal text-muted-foreground"
                                    >
                                        · {{ entry.member }}
                                    </span>
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ entry.occurred_on }}
                                    <span v-if="entry.note">
                                        · {{ entry.note }}</span
                                    >
                                </p>
                            </div>

                            <span
                                class="tabular text-sm font-semibold"
                                :class="
                                    entry.is_outflow
                                        ? 'text-red-600 dark:text-red-400'
                                        : 'text-brand-700 dark:text-brand-300'
                                "
                            >
                                {{ formatMoney(entry.amount_ngwee) }}
                            </span>
                        </li>
                    </ul>

                    <template #footer>
                        <Link
                            href="/app/fund/ledger"
                            class="text-sm font-medium text-brand-700 hover:underline dark:text-brand-300"
                        >
                            Open the full ledger →
                        </Link>
                    </template>
                </AppCard>

                <AppCard title="Waiting on the committee">
                    <dl class="space-y-4 text-sm">
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-muted-foreground">Open claims</dt>
                            <dd class="tabular font-semibold">
                                {{ openClaims }}
                            </dd>
                        </div>
                        <div class="flex items-baseline justify-between gap-4">
                            <dt class="text-muted-foreground">
                                Transfers to send
                            </dt>
                            <dd class="tabular font-semibold">
                                {{ pendingTransfers }}
                            </dd>
                        </div>
                    </dl>

                    <template #footer>
                        <div class="flex flex-wrap gap-2">
                            <Link href="/app/fund/claims">
                                <AppButton size="sm" variant="outline">
                                    Claims
                                </AppButton>
                            </Link>
                            <Link href="/app/fund/apportionment">
                                <AppButton size="sm" variant="outline">
                                    Apportionment
                                </AppButton>
                            </Link>
                        </div>
                    </template>
                </AppCard>
            </div>
        </div>

        <ClientOnly>
            <Modal
                v-model:open="contributionOpen"
                title="Record a social fund contribution"
                :description="
                    rules
                        ? `${formatMoney(rules.contribution_ngwee)} exactly, once per member, paid in full.`
                        : undefined
                "
            >
                <div class="space-y-4">
                    <FormField
                        label="Member"
                        :error="form.errors.member_id"
                        required
                    >
                        <SelectInput
                            v-model="form.member_id"
                            :options="unpaidOptions"
                            placeholder="Choose a member"
                        />
                    </FormField>

                    <FormField
                        label="Amount"
                        :error="form.errors.amount_ngwee"
                        hint="The constitution sets one figure; a part payment is refused."
                        required
                    >
                        <MoneyInput
                            v-model="form.amount_ngwee"
                            :steppers="false"
                        />
                    </FormField>

                    <FormField label="Paid on" :error="form.errors.occurred_on">
                        <input
                            v-model="form.occurred_on"
                            type="date"
                            class="h-10 w-full rounded-lg border border-input bg-card px-3 text-sm"
                        />
                    </FormField>

                    <FormField label="Note" :error="form.errors.note">
                        <TextareaInput v-model="form.note" :rows="2" />
                    </FormField>
                </div>

                <template #footer>
                    <AppButton
                        variant="ghost"
                        @click="contributionOpen = false"
                    >
                        Cancel
                    </AppButton>
                    <AppButton :loading="form.processing" @click="submit">
                        Record contribution
                    </AppButton>
                </template>
            </Modal>
        </ClientOnly>
    </AdminLayout>
</template>
