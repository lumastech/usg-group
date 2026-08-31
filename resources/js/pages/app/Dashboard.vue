<script setup lang="ts">
/**
 * Committee landing page: the group's position in the cycle at a glance.
 *
 * Every figure comes pre-formatted from CycleOverview, so this page renders what
 * the reports render — it never recomputes a total of its own. The month's
 * declaration and trading windows are left to CycleBanner in the layout.
 */
import { Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    ClipboardList,
    Coins,
    Handshake,
    HeartHandshake,
    PiggyBank,
    TriangleAlert,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';

import {
    AppCard,
    EmptyState,
    StatCard,
    StatusBadge,
    WindowCountdown,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { DeclarationMonth } from '@/types/declarations';
import type { TradingSessionStatus } from '@/types/enums';

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
        loans_outstanding?: string;
        cash_position?: string;
        cash_position_ngwee?: number;
        social_fund_balance?: string;
        social_fund_balance_ngwee?: number;
    };
    /** Present only when the user holds loans.view. */
    lending?: {
        outstanding_ngwee: number;
        loans_running: number;
        queue_count: number;
        queue_ngwee: number;
        members_penalised_this_month: number;
    };
    /** Present only when the user holds fund.view. */
    fund?: {
        balance_ngwee: number;
        balance: string;
        contributions_outstanding: number;
    };
    /** Present only when the user holds loans.view. */
    target?: {
        target_ngwee: number;
        borrowed_ngwee: number;
        shortfall_ngwee: number;
        progress_percent: number;
        members_at_target: number;
        members_under_target: number;
    };
    /** Present only when the user holds loans.view. */
    risk?: {
        members: number;
        shortfall_ngwee: number;
        minimum_monthly_ngwee: number;
        horizon_months: number;
        worst: {
            member_id: number;
            full_name: string;
            shortfall_ngwee: number;
        }[];
    };
    /** Present only when the user holds reports.view. */
    compliance?: {
        unpaid_contributions: number;
        unpaid_joining_fees: number;
        late_declarations: number;
        late_declarations_this_month: number;
        declarations_submitted_this_month: number;
        missed_installments: number;
    };
}

/** Which widgets the server decided this user may see. */
type Widgets = {
    savings: boolean;
    lending: boolean;
    risk: boolean;
    target: boolean;
    fund: boolean;
    compliance: boolean;
    shareout: boolean;
    wallets: boolean;
};

/**
 * What the group owes its members on demand, and whether last night's check agreed.
 *
 * The variance comes from the recorded reconciliation run rather than being computed
 * here — that check asks the provider for its balance, and a dashboard load is not the
 * place for a network call.
 */
type WalletFloat = {
    liability_ngwee: number;
    group_ngwee: number;
    checked_on: string | null;
    variance_ngwee: number | null;
    balances: boolean | null;
};

interface MissingMember {
    id: number;
    member_number: number;
    full_name: string;
}

/** The month's window, with what it is still waiting on. */
type MonthWindow = DeclarationMonth & {
    missing_declarations: number;
    session_status: TradingSessionStatus | null;
    session_id: number | null;
};

const props = defineProps<{
    overview: Overview | null;
    widgets: Widgets;
    membersMissingSavings?: MissingMember[];
    monthWindow?: MonthWindow | null;
    walletFloat?: WalletFloat | null;
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
            <!-- Where the month is, and what it is still waiting for: the two
                 questions the committee opens this page to answer in trading week. -->
            <div v-if="monthWindow" class="space-y-3">
                <WindowCountdown
                    :window="monthWindow.window"
                    :seconds-remaining="monthWindow.seconds_remaining"
                />

                <div class="grid gap-4 sm:grid-cols-3">
                    <Link href="/app/declarations" class="block">
                        <StatCard
                            label="Missing declarations"
                            :value="monthWindow.missing_declarations"
                            :icon="ClipboardList"
                            :accent="
                                monthWindow.missing_declarations > 0
                                    ? 'gold'
                                    : 'none'
                            "
                            :hint="`Active members not on the ${monthWindow.label} sheet`"
                        />
                    </Link>

                    <Link href="/app/trading" class="block">
                        <StatCard
                            label="Trading session"
                            :value="
                                monthWindow.session_status === null
                                    ? 'Not opened'
                                    : monthWindow.session_status === 'open'
                                      ? 'Open'
                                      : 'Concluded'
                            "
                            :icon="Handshake"
                            :hint="`Concludes ${formatDate(monthWindow.trading_concludes_on)}`"
                        />
                    </Link>

                    <AppCard class="flex items-center justify-between gap-3">
                        <span class="text-sm text-muted-foreground"
                            >Declaration window</span
                        >
                        <StatusBadge
                            :status="monthWindow.status"
                            :label="
                                monthWindow.declarations_open
                                    ? 'Open'
                                    : 'Closed'
                            "
                        />
                    </AppCard>
                </div>
            </div>

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

            <!-- Lending, the fund and the group's cash position. Each tile is
                 rendered only when the server sent the section behind it. -->
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    v-if="widgets.lending && overview.lending"
                    label="Loans outstanding"
                    :ngwee="overview.lending.outstanding_ngwee"
                    :hint="`${overview.lending.loans_running} loans running`"
                    accent="brand"
                />
                <StatCard
                    v-if="widgets.lending && overview.lending"
                    label="In the queue"
                    :ngwee="overview.lending.queue_ngwee"
                    :hint="`${overview.lending.queue_count} approved and awaiting the trading day`"
                />
                <StatCard
                    v-if="widgets.lending && overview.money.cash_position"
                    label="Cash position"
                    :value="overview.money.cash_position"
                    :icon="Wallet"
                    hint="Savings and interest not currently out on loan"
                />
                <Link
                    v-if="widgets.wallets && walletFloat"
                    href="/app/wallets"
                    class="block"
                >
                    <StatCard
                        label="Owed to members"
                        :ngwee="walletFloat.liability_ngwee"
                        :icon="Wallet"
                        :hint="
                            walletFloat.balances === false
                                ? 'The float did not agree with the money the group holds'
                                : walletFloat.checked_on
                                  ? 'Backed by money the group holds, checked overnight'
                                  : 'Wallet balances — not yet checked against the provider'
                        "
                        :accent="
                            walletFloat.balances === false ? 'gold' : 'none'
                        "
                    />
                </Link>
                <Link
                    v-if="widgets.fund && overview.fund"
                    href="/app/fund"
                    class="block"
                >
                    <StatCard
                        label="Social fund"
                        :ngwee="overview.fund.balance_ngwee"
                        :icon="HeartHandshake"
                        :hint="
                            overview.fund.contributions_outstanding > 0
                                ? `${overview.fund.contributions_outstanding} contribution(s) unpaid`
                                : 'Every member paid up'
                        "
                    />
                </Link>
                <StatCard
                    v-if="widgets.lending && overview.lending"
                    label="Penalised this month"
                    :value="overview.lending.members_penalised_this_month"
                    :icon="TriangleAlert"
                    hint="Members charged a late or missed-installment penalty"
                />
                <StatCard
                    v-if="overview.month && !overview.month.registration_open"
                    label="Membership"
                    value="Closed"
                    hint="Registration closed after cycle month 3"
                />
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <!-- Members whose loans have outrun their savings, and what the next
                     three months must bring in to put them level again. -->
                <AppCard
                    v-if="widgets.risk && overview.risk"
                    title="Under water"
                    :description="`Negative net value, with the minimum repayments over ${overview.risk.horizon_months} months at 5% a month`"
                    class="lg:col-span-2"
                >
                    <div class="space-y-3">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <p
                                    class="text-xs text-muted-foreground uppercase"
                                >
                                    Members
                                </p>
                                <p class="tabular text-xl font-semibold">
                                    {{ overview.risk.members }}
                                </p>
                            </div>
                            <div>
                                <p
                                    class="text-xs text-muted-foreground uppercase"
                                >
                                    Total shortfall
                                </p>
                                <p class="tabular text-xl font-semibold">
                                    {{
                                        formatMoney(
                                            overview.risk.shortfall_ngwee,
                                        )
                                    }}
                                </p>
                            </div>
                            <div>
                                <p
                                    class="text-xs text-muted-foreground uppercase"
                                >
                                    Minimum first month
                                </p>
                                <p class="tabular text-xl font-semibold">
                                    {{
                                        formatMoney(
                                            overview.risk.minimum_monthly_ngwee,
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <ul v-if="overview.risk.worst.length" class="space-y-1">
                            <li
                                v-for="row in overview.risk.worst"
                                :key="row.member_id"
                                class="flex items-center justify-between gap-3 rounded px-2 py-1 text-sm odd:bg-muted/40"
                            >
                                <span class="truncate">{{
                                    row.full_name
                                }}</span>
                                <span class="tabular shrink-0 font-medium">
                                    {{ formatMoney(row.shortfall_ngwee) }}
                                </span>
                            </li>
                        </ul>

                        <p v-else class="text-sm text-muted-foreground">
                            Every member's savings and interest cover what they
                            owe.
                        </p>

                        <Link
                            href="/app/risk"
                            class="inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                        >
                            Open the full projection
                        </Link>
                    </div>
                </AppCard>

                <!-- The K50,000 target. A goal the committee talks about, never a rule. -->
                <AppCard
                    v-if="widgets.target && overview.target"
                    title="Borrowing target"
                    description="The group's income is the interest its members pay"
                >
                    <div class="space-y-3">
                        <div>
                            <p class="tabular text-2xl font-semibold">
                                {{ overview.target.progress_percent }}%
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    formatMoney(overview.target.borrowed_ngwee)
                                }}
                                of
                                {{ formatMoney(overview.target.target_ngwee) }}
                            </p>
                        </div>

                        <div
                            class="h-2 w-full overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-brand-500"
                                :style="{
                                    width: `${Math.min(100, overview.target.progress_percent)}%`,
                                }"
                            />
                        </div>

                        <p class="text-sm text-muted-foreground">
                            {{ overview.target.members_at_target }} at target ·
                            {{ overview.target.members_under_target }} still
                            short
                        </p>

                        <Link
                            href="/app/loans/targets"
                            class="inline-block text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                        >
                            Per member
                        </Link>
                    </div>
                </AppCard>
            </div>

            <!-- What the committee chases: dues unpaid, late declarations, missed
                 installments. -->
            <AppCard
                v-if="widgets.compliance && overview.compliance"
                title="Compliance"
                description="What the committee is chasing this cycle"
            >
                <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs text-muted-foreground uppercase">
                            Unpaid contributions
                        </dt>
                        <dd class="tabular text-xl font-semibold">
                            {{ overview.compliance.unpaid_contributions }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground uppercase">
                            Unpaid joining fees
                        </dt>
                        <dd class="tabular text-xl font-semibold">
                            {{ overview.compliance.unpaid_joining_fees }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground uppercase">
                            Late declarations
                        </dt>
                        <dd class="tabular text-xl font-semibold">
                            {{ overview.compliance.late_declarations }}
                        </dd>
                        <p class="text-xs text-muted-foreground">
                            {{
                                overview.compliance.late_declarations_this_month
                            }}
                            this month
                        </p>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground uppercase">
                            Missed installments
                        </dt>
                        <dd class="tabular text-xl font-semibold">
                            {{ overview.compliance.missed_installments }}
                        </dd>
                    </div>
                </dl>
            </AppCard>
        </div>
    </AdminLayout>
</template>
