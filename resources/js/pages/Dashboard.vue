<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import { dashboard } from '@/routes';

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

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const formatDate = (value: string) =>
    new Date(value).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

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
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div v-if="!overview" class="rounded-xl border border-sidebar-border/70 p-8 text-center dark:border-sidebar-border">
            <p class="text-sm text-muted-foreground">
                No cycle has been set up yet. Run the seeders to create the 2025–2026 cycle.
            </p>
        </div>

        <template v-else>
            <!-- Cycle header -->
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ overview.cycle.name }} cycle</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ formatDate(overview.cycle.starts_on) }} – {{ formatDate(overview.cycle.ends_on) }}
                        <span v-if="overview.month"> · currently {{ overview.month.label }} (month {{ overview.month.sequence }} of 12)</span>
                    </p>
                </div>

                <div
                    v-if="deadline"
                    class="rounded-lg border px-4 py-2 text-right"
                    :class="deadline.passed || deadline.urgent
                        ? 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/40'
                        : 'border-sidebar-border/70 dark:border-sidebar-border'"
                >
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">All loans repaid by</p>
                    <p class="text-lg font-semibold">{{ formatDate(overview.cycle.final_repayment_date) }}</p>
                    <p class="text-xs" :class="deadline.passed || deadline.urgent ? 'font-medium text-red-600 dark:text-red-400' : 'text-muted-foreground'">
                        {{ deadline.passed ? `${deadline.days} days overdue` : `${deadline.days} days remaining` }}
                    </p>
                </div>
            </div>

            <!-- Status strip -->
            <div v-if="overview.month" class="flex flex-wrap gap-2">
                <span
                    class="rounded-full px-3 py-1 text-xs font-medium"
                    :class="overview.month.declarations_open
                        ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300'
                        : 'bg-muted text-muted-foreground'"
                >
                    Declarations {{ overview.month.declarations_open ? 'open until ' + formatDate(overview.month.declarations_close_at) : 'closed' }}
                </span>
                <span class="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground">
                    Trading from {{ formatDate(overview.month.trading_starts_on) }} · disbursement {{ formatDate(overview.month.disbursement_on) }}
                </span>
                <span
                    v-if="overview.month.lockdown_active"
                    class="rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-300"
                >
                    Lockdown — no new loans, savings capped at {{ overview.month.savings_cap }}
                </span>
                <span
                    v-if="!overview.month.registration_open"
                    class="rounded-full bg-muted px-3 py-1 text-xs font-medium text-muted-foreground"
                >
                    Membership closed
                </span>
            </div>

            <!-- Money tiles -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Total savings</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ overview.money.total_savings }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">{{ overview.money.month_savings }} this month</p>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Interest earned</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ overview.money.total_interest }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">Pooled and shared by savings</p>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Group wealth</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ overview.money.group_wealth }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">Savings plus interest</p>
                </div>
                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <p class="text-xs uppercase tracking-wide text-muted-foreground">Loans outstanding</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-muted-foreground">
                        {{ overview.money.loans_outstanding ?? '—' }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">Available once the loans module lands</p>
                </div>
            </div>

            <!-- Membership -->
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="rounded-xl border border-sidebar-border/70 p-4 lg:col-span-1 dark:border-sidebar-border">
                    <h2 class="text-sm font-semibold">Membership</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Active</dt>
                            <dd class="font-medium tabular-nums">{{ overview.members.active }}</dd>
                        </div>
                        <div v-if="overview.members.left_early" class="flex justify-between">
                            <dt class="text-muted-foreground">Left early</dt>
                            <dd class="font-medium tabular-nums">{{ overview.members.left_early }}</dd>
                        </div>
                        <div v-if="overview.members.expelled" class="flex justify-between">
                            <dt class="text-muted-foreground">Expelled</dt>
                            <dd class="font-medium tabular-nums">{{ overview.members.expelled }}</dd>
                        </div>
                        <div v-if="overview.members.deceased" class="flex justify-between">
                            <dt class="text-muted-foreground">Deceased</dt>
                            <dd class="font-medium tabular-nums">{{ overview.members.deceased }}</dd>
                        </div>
                        <div class="flex justify-between border-t pt-2">
                            <dt class="text-muted-foreground">Diaspora</dt>
                            <dd class="font-medium tabular-nums">{{ overview.members.diaspora }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-muted-foreground">Joining fees unpaid</dt>
                            <dd class="font-medium tabular-nums">{{ overview.members.joining_fees_outstanding }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- Savings chase list -->
                <div class="rounded-xl border border-sidebar-border/70 p-4 lg:col-span-2 dark:border-sidebar-border">
                    <div class="flex items-baseline justify-between">
                        <h2 class="text-sm font-semibold">No savings recorded this month</h2>
                        <span class="text-xs text-muted-foreground">
                            {{ overview.money.members_saved_this_month }} of {{ overview.members.active }} have saved
                        </span>
                    </div>

                    <div v-if="membersMissingSavings === undefined" class="mt-3 space-y-2">
                        <div v-for="n in 4" :key="n" class="h-8 animate-pulse rounded bg-muted"></div>
                    </div>

                    <p v-else-if="membersMissingSavings.length === 0" class="mt-3 text-sm text-muted-foreground">
                        Every active member has saved this month.
                    </p>

                    <ul v-else class="mt-3 grid gap-1 sm:grid-cols-2">
                        <li
                            v-for="member in membersMissingSavings"
                            :key="member.id"
                            class="flex items-center gap-2 rounded px-2 py-1 text-sm odd:bg-muted/40"
                        >
                            <span class="w-6 text-xs tabular-nums text-muted-foreground">{{ member.member_number }}</span>
                            <span class="truncate">{{ member.full_name }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </template>
    </div>
</template>
