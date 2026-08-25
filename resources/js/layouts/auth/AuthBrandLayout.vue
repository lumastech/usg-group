<script setup lang="ts">
/**
 * The front door, now that there is no public landing page. It carries the same
 * dark brand-950 panel and gold accents the marketing page used, so the first
 * screen a member sees already looks like the building they are walking into.
 *
 * On phones the panel collapses to a slim band and the form takes the screen —
 * most members sign in from a handset.
 */
import { Link } from '@inertiajs/vue3';
import { BookLock, CalendarCheck, Scale, Smartphone } from '@lucide/vue';

import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();

const assurances = [
    {
        icon: Scale,
        title: 'The two-person rule',
        body: 'Money never moves on one signature. Outflows need a second officer to confirm.',
    },
    {
        icon: BookLock,
        title: 'An immutable ledger',
        body: 'Nothing is edited or deleted. A mistake is corrected by a reversing entry.',
    },
    {
        icon: Smartphone,
        title: 'Built for phones',
        body: 'Declare, track savings and follow your loans from a small screen first.',
    },
];
</script>

<template>
    <div class="min-h-svh bg-background lg:grid lg:grid-cols-[1.05fr_0.95fr]">
        <!-- Brand panel: full column on desktop, a slim header band on phones. -->
        <div
            class="relative flex flex-col overflow-hidden border-b border-white/10 bg-brand-950 text-white lg:justify-between lg:border-r lg:border-b-0 lg:p-12"
        >
            <div
                class="pointer-events-none absolute -top-40 -right-32 size-[36rem] rounded-full bg-gold-400/12 blur-3xl"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -bottom-56 -left-40 size-[32rem] rounded-full bg-brand-500/20 blur-3xl"
                aria-hidden="true"
            />

            <Link
                :href="home()"
                class="relative flex items-center gap-3 rounded-lg px-6 py-6 focus-visible:ring-2 focus-visible:ring-gold-400 focus-visible:outline-none lg:p-0"
            >
                <span
                    class="flex size-9 items-center justify-center rounded-lg bg-gold-400 font-semibold text-brand-950"
                >
                    U
                </span>
                <span class="leading-tight">
                    <span class="block text-sm font-semibold tracking-tight">
                        Unity Savings Group
                    </span>
                    <span class="block text-xs text-white/55">
                        Village banking, kept straight
                    </span>
                </span>
            </Link>

            <div class="relative hidden lg:block">
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-gold-400/30 bg-gold-400/10 px-3 py-1 text-xs font-medium text-gold-200"
                >
                    <CalendarCheck class="size-3.5" />
                    Cycle 2025 &ndash; 26 &middot; December to November
                </span>

                <h2
                    class="mt-6 max-w-lg text-4xl leading-[1.08] font-semibold tracking-tight text-balance xl:text-5xl"
                >
                    Every kwacha the group saves,
                    <span class="text-gold-300">accounted for.</span>
                </h2>

                <ul class="mt-10 max-w-md space-y-5">
                    <li
                        v-for="item in assurances"
                        :key="item.title"
                        class="flex gap-4"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-lg border border-white/10 bg-white/[0.06] text-gold-300"
                        >
                            <component :is="item.icon" class="size-4.5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-white">
                                {{ item.title }}
                            </p>
                            <p
                                class="mt-1 text-sm leading-relaxed text-white/60"
                            >
                                {{ item.body }}
                            </p>
                        </div>
                    </li>
                </ul>
            </div>

            <p class="relative hidden text-sm text-white/45 lg:block">
                Unity Savings Group &middot; Cycle 2025&ndash;26 &middot;
                Amounts in ZMW
            </p>
        </div>

        <!-- Form panel -->
        <div class="flex items-center justify-center px-6 py-12 lg:px-12">
            <div class="w-full max-w-sm">
                <div v-if="title || description" class="mb-8">
                    <h1
                        v-if="title"
                        class="text-2xl font-semibold tracking-tight text-balance"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="mt-2 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>

                <slot />

                <p class="mt-10 text-xs text-muted-foreground/70 lg:hidden">
                    Unity Savings Group &middot; Cycle 2025&ndash;26
                </p>
            </div>
        </div>
    </div>
</template>
