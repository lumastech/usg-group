<script setup lang="ts">
/**
 * Committee landing page: the group's position in the cycle at a glance.
 *
 * Every figure comes pre-formatted from CycleOverview, so this page renders what
 * the reports render — it never recomputes a total of its own. The month's
 * declaration and trading windows are left to CycleBanner in the layout.
 */
import { CalendarDays, Coins, PiggyBank, Wallet } from '@lucide/vue';
import { computed } from 'vue';

import { AppCard, EmptyState, StatCard } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';

interface Overview {
    cycle: {
        name: string;
        status: string;
        starts_on: string;
        ends_on: string;
        final_repayment_date: string;
        days_to_final_repayment: number;
        deadline_passed: boolean;
    };
    month: {
        label: string;
        sequence: number;
        declarations_open: boolean;
        declarations_open_at: string;
        declarations_close_at: string;
        trading_starts_on: string;
        disbursement_on: string;
        lockdown_active: boolean;
        registration_open: boolean;
        savings_cap: string | null;
    } | null;
    members: {
        total: number;
        active: number;
        left_early: number;
        expelled: number;
        deceased: number;
        diaspora: number;
        joining_fees_outstanding: number;
    };
    money: {
        total_savings: string;
        total_interest: string;
        group_wealth: string;
        month_savings: string;
        members_saved_this_month: number;
        ledger_started: boolean;
        loans_outstanding: string | null;
        social_fund_balance: string | null;
        negative_net_value_members: number | null;
    };
}

interface MissingMember {
    id: number;
    member_number: number;
    full_name: string;
}

const props = defineProps<{
    overview: Overview | null;
    membersMissingSavings?: MissingMember[];
}>();

const formatDate = (value: string) =>
    new Date(value).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/** The run-up to the final repayment date, which the whole cycle is timed against. */
const deadline = computed(() => {
    const cycle = props.overview?.cycle;

    if (!cycle) {
        return null;
    }

    const days = cycle.days_to_final_repayment;

    return {
        days: Math.abs(days),
        passed: cycle.deadline_passed,
        urgent: !cycle.deadline_passed && days <= 60,
    };
});

const description = computed(() => {
    const cycle = props.overview?.cycle;

    if (!cycle) {
        return "The group's position this cycle";
    }

    const month = props.overview?.month;
    const range = `${formatDate(cycle.starts_on)} – ${formatDate(cycle.ends_on)}`;

    return month
        ? `${range} · ${month.label} (month ${month.sequence} of 12)`
        : range;
});
</script>

<template>
    <AdminLayout
        title="Dashboard"
        :heading="overview ? `${overview.cycle.name} cycle` : 'Dashboard'"
        :description="description"
    >
        <AppCard v-if="!overview">
            <EmptyState
                title="No active cycle"
                description="Seed or activate a cycle to see the group's position here."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Total savings"
                    :value="overview.money.total_savings"
                    :icon="PiggyBank"
                    accent="brand"
                    :hint="`${overview.money.month_savings} this month`"
                />
                <StatCard
                    label="Interest earned"
                    :value="overview.money.total_interest"
                    :icon="Coins"
                    hint="Pooled and shared by savings"
                />
                <StatCard
                    label="Group wealth"
                    :value="overview.money.group_wealth"
                    :icon="Wallet"
                    hint="Savings plus interest"
                />
                <StatCard
                    label="All loans repaid by"
                    :value="formatDate(overview.cycle.final_repayment_date)"
                    :icon="CalendarDays"
                    :accent="
                        deadline?.passed || deadline?.urgent ? 'gold' : 'none'
                    "
                    :hint="
                        deadline?.passed
                            ? `${deadline.days} days overdue`
                            : `${deadline?.days} days remaining`
                    "
                />
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <AppCard title="Membership" class="lg:col-span-1">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Active</dt>
                            <dd class="tabular font-medium">
                                {{ overview.members.active }}
                            </dd>
                        </div>
                        <div
                            v-if="overview.members.left_early"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">Left early</dt>
                            <dd class="tabular font-medium">
                                {{ overview.members.left_early }}
                            </dd>
                        </div>
                        <div
                            v-if="overview.members.expelled"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">Expelled</dt>
                            <dd class="tabular font-medium">
                                {{ overview.members.expelled }}
                            </dd>
                        </div>
                        <div
                            v-if="overview.members.deceased"
                            class="flex justify-between"
                        >
                            <dt class="text-muted-foreground">Deceased</dt>
                            <dd class="tabular font-medium">
                                {{ overview.members.deceased }}
                            </dd>
                        </div>
                        <div
                            class="flex justify-between border-t border-border pt-2"
                        >
                            <dt class="text-muted-foreground">Diaspora</dt>
                            <dd class="tabular font-medium">
                                {{ overview.members.diaspora }}
                            </dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">
                                Joining fees unpaid
                            </dt>
                            <dd class="tabular font-medium">
                                {{ overview.members.joining_fees_outstanding }}
                            </dd>
                        </div>
                    </dl>
                </AppCard>

                <AppCard
                    title="No savings recorded this month"
                    :description="`${overview.money.members_saved_this_month} of ${overview.members.active} active members have saved`"
                    class="lg:col-span-2"
                >
                    <!-- Deferred: the chase list arrives on its own request. -->
                    <div
                        v-if="membersMissingSavings === undefined"
                        class="space-y-2"
                    >
                        <div
                            v-for="n in 4"
                            :key="n"
                            class="h-8 animate-pulse rounded bg-muted"
                        />
                    </div>

                    <p
                        v-else-if="membersMissingSavings.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Every active member has saved this month.
                    </p>

                    <ul v-else class="grid gap-1 sm:grid-cols-2">
                        <li
                            v-for="member in membersMissingSavings"
                            :key="member.id"
                            class="flex items-center gap-2 rounded px-2 py-1 text-sm odd:bg-muted/40"
                        >
                            <span
                                class="tabular w-6 text-xs text-muted-foreground"
                            >
                                {{ member.member_number }}
                            </span>
                            <span class="truncate">{{ member.full_name }}</span>
                        </li>
                    </ul>
                </AppCard>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <StatCard
                    label="Loans outstanding"
                    :value="overview.money.loans_outstanding ?? '—'"
                    hint="Available once the loans module lands"
                />
                <StatCard
                    label="Social fund"
                    :value="overview.money.social_fund_balance ?? '—'"
                    hint="Available once the fund module lands"
                />
                <StatCard
                    v-if="overview.month && !overview.month.registration_open"
                    label="Membership"
                    value="Closed"
                    hint="Registration closed after cycle month 3"
                />
            </div>
        </div>
    </AdminLayout>
</template>
