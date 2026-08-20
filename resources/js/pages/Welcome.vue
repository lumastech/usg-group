<script setup lang="ts">
/**
 * The public front door. It is the only page an unauthenticated visitor sees, so
 * it explains what the group runs on and then gets out of the way — every figure
 * here is structural (dates, rules, rhythm), never a real balance.
 */
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BookLock,
    CalendarCheck,
    HandCoins,
    PiggyBank,
    Scale,
    Smartphone,
    Users,
} from '@lucide/vue';

import { dashboard, login, register } from '@/routes';

const features = [
    {
        icon: PiggyBank,
        title: 'Savings & shares',
        body: 'Monthly declarations in K500 steps, capped and closed automatically once the window shuts.',
    },
    {
        icon: HandCoins,
        title: 'Loans & interest',
        body: 'Applications, approvals and disbursement run to the group constitution — interest accrues on schedule, not by memory.',
    },
    {
        icon: Scale,
        title: 'The two-person rule',
        body: 'Money never moves on one signature. Outflows need a second officer to confirm, captured at the moment of approval.',
    },
    {
        icon: BookLock,
        title: 'An immutable ledger',
        body: 'Nothing is edited or deleted. A mistake is corrected by a reversing entry, so the history stays readable years later.',
    },
    {
        icon: Users,
        title: 'Share-out',
        body: 'At cycle end, interest is apportioned by each member’s share weight and the payout schedule is produced for you.',
    },
    {
        icon: Smartphone,
        title: 'Built for phones',
        body: 'Members declare, track savings and follow their loans from a portal designed for a small screen first.',
    },
];

const rhythm = [
    {
        days: 'Day 1 – 3',
        title: 'Declarations',
        body: 'The window opens 08:00 on day one. Members declare what they will save this month.',
    },
    {
        days: 'Day 4 – 7',
        title: 'Trading',
        body: 'Savings are collected and loan applications are received and reviewed.',
    },
    {
        days: 'Day 7',
        title: 'Disbursement',
        body: 'Approved loans are paid out, dual-approved and logged against the cycle.',
    },
    {
        days: 'Month end',
        title: 'Repayment',
        body: 'Interest is posted and balances roll forward. The month closes; it does not reopen.',
    },
];

const milestones = [
    {
        date: 'Dec 2025',
        label: 'Cycle opens',
        note: 'Registration and the first declarations.',
    },
    {
        date: 'Sep 2026',
        label: 'Loan lockdown',
        note: 'No new loans; savings capped at K500 a month.',
    },
    {
        date: '7 Nov 2026',
        label: 'Final repayment',
        note: 'Every outstanding loan must be cleared.',
    },
    {
        date: 'Nov 2026',
        label: 'Share-out',
        note: 'Interest apportioned, payouts scheduled.',
    },
];
</script>

<template>
    <Head title="Unity Savings Group" />

    <div class="min-h-screen bg-background text-foreground">
        <!-- Hero keeps the app's dark anchor colour, so signing in feels like walking further into the same building. -->
        <div class="relative overflow-hidden bg-brand-950 text-white">
            <div
                class="pointer-events-none absolute -top-40 -right-32 size-[36rem] rounded-full bg-gold-400/12 blur-3xl"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -bottom-56 -left-40 size-[32rem] rounded-full bg-brand-500/20 blur-3xl"
                aria-hidden="true"
            />

            <header class="relative mx-auto max-w-6xl px-6 py-6">
                <nav class="flex items-center justify-between gap-6">
                    <Link
                        :href="'/'"
                        class="flex items-center gap-3 rounded-lg focus-visible:ring-2 focus-visible:ring-gold-400 focus-visible:outline-none"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-lg bg-gold-400 font-semibold text-brand-950"
                        >
                            U
                        </span>
                        <span class="leading-tight">
                            <span
                                class="block text-sm font-semibold tracking-tight"
                            >
                                Unity Savings Group
                            </span>
                            <span class="block text-xs text-white/55">
                                Village banking, kept straight
                            </span>
                        </span>
                    </Link>

                    <div class="flex items-center gap-2">
                        <a
                            href="#how-it-works"
                            class="hidden rounded-lg px-3 py-2 text-sm text-white/70 transition hover:text-white sm:inline-block"
                        >
                            How it works
                        </a>
                        <a
                            href="#the-cycle"
                            class="hidden rounded-lg px-3 py-2 text-sm text-white/70 transition hover:text-white sm:inline-block"
                        >
                            The cycle
                        </a>
                        <Link
                            v-if="$page.props.auth.user"
                            :href="dashboard()"
                            class="inline-flex h-10 items-center rounded-lg bg-gold-400 px-4 text-sm font-medium text-brand-950 transition hover:bg-gold-500 focus-visible:ring-2 focus-visible:ring-gold-400 focus-visible:ring-offset-2 focus-visible:ring-offset-brand-950 focus-visible:outline-none"
                        >
                            Go to dashboard
                        </Link>
                        <Link
                            v-else
                            :href="login()"
                            class="inline-flex h-10 items-center rounded-lg border border-white/20 px-4 text-sm font-medium text-white transition hover:border-white/40 hover:bg-white/5 focus-visible:ring-2 focus-visible:ring-gold-400 focus-visible:outline-none"
                        >
                            Sign in
                        </Link>
                    </div>
                </nav>
            </header>

            <div
                class="relative mx-auto grid max-w-6xl gap-14 px-6 pt-10 pb-20 lg:grid-cols-[1.05fr_0.95fr] lg:items-center lg:pt-16 lg:pb-28"
            >
                <div>
                    <span
                        class="inline-flex items-center gap-2 rounded-full border border-gold-400/30 bg-gold-400/10 px-3 py-1 text-xs font-medium text-gold-200"
                    >
                        <CalendarCheck class="size-3.5" />
                        Cycle 2025 &ndash; 26 &middot; December to November
                    </span>

                    <h1
                        class="mt-6 text-4xl leading-[1.08] font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl"
                    >
                        Every kwacha the group saves,
                        <span class="text-gold-300">accounted for.</span>
                    </h1>

                    <p
                        class="mt-6 max-w-xl text-lg leading-relaxed text-white/70"
                    >
                        Unity runs the savings, loans and share-out of a
                        thirty-member village bank on one ledger &mdash; one
                        that records corrections instead of hiding them, and
                        asks two officers before any money moves.
                    </p>

                    <div class="mt-9 flex flex-wrap items-center gap-3">
                        <Link
                            :href="
                                $page.props.auth.user ? dashboard() : login()
                            "
                            class="inline-flex h-12 items-center gap-2 rounded-lg bg-gold-400 px-6 text-base font-medium text-brand-950 transition hover:bg-gold-500 focus-visible:ring-2 focus-visible:ring-gold-400 focus-visible:ring-offset-2 focus-visible:ring-offset-brand-950 focus-visible:outline-none"
                        >
                            {{
                                $page.props.auth.user
                                    ? 'Open the dashboard'
                                    : 'Sign in to your account'
                            }}
                            <ArrowRight class="size-4" />
                        </Link>
                        <a
                            href="#how-it-works"
                            class="inline-flex h-12 items-center rounded-lg border border-white/20 px-6 text-base font-medium text-white transition hover:border-white/40 hover:bg-white/5 focus-visible:ring-2 focus-visible:ring-gold-400 focus-visible:outline-none"
                        >
                            See how it works
                        </a>
                    </div>

                    <p
                        v-if="!$page.props.auth.user"
                        class="mt-5 text-sm text-white/50"
                    >
                        Membership is by invitation.
                        <Link
                            :href="register()"
                            class="font-medium text-gold-300 underline underline-offset-4 hover:text-gold-200"
                        >
                            Been invited? Set up your account
                        </Link>
                    </p>
                </div>

                <!-- The month, not a balance: the rhythm is the thing a new member needs to learn. -->
                <div class="relative">
                    <div
                        class="rounded-2xl border border-white/10 bg-white/[0.04] p-6 shadow-pop backdrop-blur-sm sm:p-8"
                    >
                        <div class="flex items-center justify-between gap-4">
                            <h2
                                class="text-sm font-semibold tracking-tight text-white"
                            >
                                The month, every month
                            </h2>
                            <span
                                class="rounded-full bg-white/10 px-2.5 py-1 text-[11px] font-medium text-white/60"
                            >
                                Fixed by the constitution
                            </span>
                        </div>

                        <ol class="mt-6 space-y-5">
                            <li
                                v-for="(step, index) in rhythm"
                                :key="step.title"
                                class="relative flex gap-4 pb-5 last:pb-0"
                            >
                                <span
                                    v-if="index < rhythm.length - 1"
                                    class="absolute top-9 bottom-0 left-[0.9375rem] w-px bg-white/12"
                                    aria-hidden="true"
                                />
                                <span
                                    class="tabular relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border border-gold-400/40 bg-brand-950 text-xs font-semibold text-gold-300"
                                >
                                    {{ index + 1 }}
                                </span>
                                <div class="min-w-0">
                                    <p
                                        class="tabular text-[11px] font-medium tracking-wide text-white/45 uppercase"
                                    >
                                        {{ step.days }}
                                    </p>
                                    <p
                                        class="mt-0.5 text-sm font-semibold text-white"
                                    >
                                        {{ step.title }}
                                    </p>
                                    <p
                                        class="mt-1 text-sm leading-relaxed text-white/60"
                                    >
                                        {{ step.body }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <section
            id="how-it-works"
            class="mx-auto max-w-6xl px-6 py-20 lg:py-28"
        >
            <div class="max-w-2xl">
                <p
                    class="text-xs font-semibold tracking-widest text-brand-600 uppercase dark:text-brand-400"
                >
                    What it handles
                </p>
                <h2
                    class="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                >
                    The whole cycle, not just the arithmetic
                </h2>
                <p class="mt-4 text-lg leading-relaxed text-muted-foreground">
                    The group already knows its rules. Unity holds them &mdash;
                    so the treasurer&rsquo;s workbook, the loan register and the
                    share-out spreadsheet stop disagreeing with each other.
                </p>
            </div>

            <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="feature in features"
                    :key="feature.title"
                    class="rounded-xl border border-border bg-card p-6 shadow-card transition-shadow hover:shadow-card-hover"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300"
                    >
                        <component :is="feature.icon" class="size-5" />
                    </span>
                    <h3 class="mt-4 text-base font-semibold tracking-tight">
                        {{ feature.title }}
                    </h3>
                    <p
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ feature.body }}
                    </p>
                </div>
            </div>
        </section>

        <section id="the-cycle" class="border-y border-border bg-muted/40">
            <div class="mx-auto max-w-6xl px-6 py-20 lg:py-28">
                <div class="max-w-2xl">
                    <p
                        class="text-xs font-semibold tracking-widest text-brand-600 uppercase dark:text-brand-400"
                    >
                        The cycle
                    </p>
                    <h2
                        class="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        Twelve months, four fixed points
                    </h2>
                    <p
                        class="mt-4 text-lg leading-relaxed text-muted-foreground"
                    >
                        Deadlines are enforced by the system, not remembered by
                        a person. When lockdown starts, lending stops.
                    </p>
                </div>

                <ol class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <li
                        v-for="milestone in milestones"
                        :key="milestone.label"
                        class="relative rounded-xl border border-border bg-card p-6 shadow-card"
                    >
                        <span
                            class="absolute inset-x-6 top-0 h-px bg-gradient-to-r from-gold-400 to-transparent"
                            aria-hidden="true"
                        />
                        <p
                            class="tabular text-xs font-medium text-muted-foreground"
                        >
                            {{ milestone.date }}
                        </p>
                        <p class="mt-2 text-base font-semibold tracking-tight">
                            {{ milestone.label }}
                        </p>
                        <p
                            class="mt-2 text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ milestone.note }}
                        </p>
                    </li>
                </ol>
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-6 py-20 lg:py-28">
            <div class="grid gap-10 lg:grid-cols-2 lg:items-center">
                <div>
                    <p
                        class="text-xs font-semibold tracking-widest text-brand-600 uppercase dark:text-brand-400"
                    >
                        Access
                    </p>
                    <h2
                        class="mt-3 text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                    >
                        Everyone sees what their role allows
                    </h2>
                    <p
                        class="mt-4 text-lg leading-relaxed text-muted-foreground"
                    >
                        Permissions are checked on the server for every action.
                        What you can see on screen is a reflection of that,
                        never a substitute for it.
                    </p>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div
                        class="rounded-xl border border-border bg-card p-6 shadow-card"
                    >
                        <h3 class="text-base font-semibold tracking-tight">
                            Committee
                        </h3>
                        <p
                            class="mt-2 text-sm leading-relaxed text-muted-foreground"
                        >
                            Chairperson, treasurer and their deputies approve
                            loans, execute payouts and record governance &mdash;
                            each action gated by its own permission.
                        </p>
                    </div>
                    <div
                        class="rounded-xl border border-border bg-card p-6 shadow-card"
                    >
                        <h3 class="text-base font-semibold tracking-tight">
                            Members
                        </h3>
                        <p
                            class="mt-2 text-sm leading-relaxed text-muted-foreground"
                        >
                            A phone-sized portal for your own declarations,
                            savings history, loan balance and share of the
                            cycle&rsquo;s interest.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section class="mx-auto max-w-6xl px-6 pb-20 lg:pb-28">
            <div
                class="relative overflow-hidden rounded-2xl bg-brand-950 px-6 py-14 text-center text-white sm:px-14"
            >
                <div
                    class="pointer-events-none absolute -top-24 left-1/2 size-[28rem] -translate-x-1/2 rounded-full bg-gold-400/12 blur-3xl"
                    aria-hidden="true"
                />
                <h2
                    class="relative text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                >
                    Pick up where the cycle left off
                </h2>
                <p
                    class="relative mx-auto mt-4 max-w-xl text-lg leading-relaxed text-white/70"
                >
                    Sign in to declare this month&rsquo;s savings, review a
                    loan, or check where the group stands.
                </p>
                <Link
                    :href="$page.props.auth.user ? dashboard() : login()"
                    class="relative mt-8 inline-flex h-12 items-center gap-2 rounded-lg bg-gold-400 px-6 text-base font-medium text-brand-950 transition hover:bg-gold-500 focus-visible:ring-2 focus-visible:ring-gold-400 focus-visible:ring-offset-2 focus-visible:ring-offset-brand-950 focus-visible:outline-none"
                >
                    {{
                        $page.props.auth.user ? 'Open the dashboard' : 'Sign in'
                    }}
                    <ArrowRight class="size-4" />
                </Link>
            </div>
        </section>

        <footer class="relative overflow-hidden bg-brand-950 text-white">
            <div
                class="pointer-events-none absolute -bottom-40 -left-32 size-[28rem] rounded-full bg-gold-400/10 blur-3xl"
                aria-hidden="true"
            />
            <div
                class="relative mx-auto flex max-w-6xl flex-col gap-4 px-6 py-8 text-sm text-white/60 sm:flex-row sm:items-center sm:justify-between"
            >
                <p>
                    Unity Savings Group &middot; Cycle 2025&ndash;26 &middot;
                    Amounts in ZMW
                </p>
                <Link
                    :href="login()"
                    class="font-medium text-white transition hover:text-gold-300"
                >
                    Sign in
                </Link>
            </div>
        </footer>
    </div>
</template>
