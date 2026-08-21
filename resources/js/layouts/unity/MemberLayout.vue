<script setup lang="ts">
/**
 * The member shell: mobile-first, bottom navigation, large touch targets.
 *
 * Most of the group reaches the portal on a phone, so this layout is built for a
 * small screen first and merely widens on desktop rather than the other way round.
 * Bottom-nav items come from the same permission-filtered config as the sidebar.
 */
import { Head, Link } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';

import { Toast } from '@/components/unity';
import { useNavigation } from '@/composables/useNavigation';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/utils';
import CycleBanner from './CycleBanner.vue';
import PortalSwitcher from './PortalSwitcher.vue';

defineProps<{ title?: string; heading?: string; description?: string }>();

const { memberItems, isActive } = useNavigation();
const { user, currentCycle } = usePermissions();
</script>

<template>
    <Head :title="title" />

    <div class="flex min-h-svh flex-col bg-background">
        <header class="sticky top-0 z-20 border-b border-border bg-sidebar">
            <div
                class="mx-auto flex h-16 w-full max-w-3xl items-center gap-3 px-4"
            >
                <!-- The header doubles as the way into /my/profile, keeping the
                     bottom nav to the five sections members use daily. -->
                <Link
                    href="/my/profile"
                    class="flex min-w-0 flex-1 items-center gap-3"
                >
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-full bg-gold-400 text-sm font-bold text-brand-950"
                    >
                        {{ user?.name?.charAt(0) ?? 'U' }}
                    </span>

                    <span class="min-w-0 flex-1 text-left">
                        <span
                            class="block truncate text-sm font-semibold text-sidebar-foreground"
                        >
                            {{ heading ?? user?.name }}
                        </span>
                        <span
                            class="block truncate text-xs text-sidebar-foreground/60"
                        >
                            {{
                                description ??
                                currentCycle?.name ??
                                'Unity Savings'
                            }}
                        </span>
                    </span>
                </Link>

                <PortalSwitcher tone="dark" />

                <!-- Notification preferences live off the header rather than the
                     bottom nav: a sixth thumb target would crowd the five sections
                     members actually use every month. -->
                <Link
                    href="/my/settings"
                    class="grid size-9 shrink-0 place-items-center rounded-lg text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent"
                    aria-label="Settings"
                >
                    <Settings class="size-4" />
                </Link>

                <Link
                    href="/logout"
                    method="post"
                    as="button"
                    class="grid size-9 shrink-0 place-items-center rounded-lg text-sidebar-foreground/70 transition-colors hover:bg-sidebar-accent"
                    aria-label="Log out"
                >
                    <LogOut class="size-4" />
                </Link>
            </div>
        </header>

        <!-- pb-24 keeps content clear of the fixed bottom nav. -->
        <main class="mx-auto w-full max-w-3xl flex-1 px-4 pt-5 pb-24">
            <CycleBanner class="mb-4" />
            <slot />
        </main>

        <nav
            class="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-card/95 backdrop-blur-sm"
            aria-label="Main"
        >
            <ul class="mx-auto flex w-full max-w-3xl items-stretch">
                <li v-for="item in memberItems" :key="item.href" class="flex-1">
                    <Link
                        :href="item.href"
                        :class="
                            cn(
                                'flex min-h-16 flex-col items-center justify-center gap-1 px-1 py-2 text-[0.6875rem] font-medium transition-colors',
                                isActive(item)
                                    ? 'text-brand-700 dark:text-brand-300'
                                    : 'text-muted-foreground hover:text-foreground',
                            )
                        "
                        :aria-current="isActive(item) ? 'page' : undefined"
                    >
                        <component
                            :is="item.icon"
                            :class="
                                cn(
                                    'size-5 shrink-0',
                                    isActive(item) && 'stroke-[2.25]',
                                )
                            "
                        />
                        <span class="truncate">{{ item.title }}</span>
                    </Link>
                </li>
            </ul>
        </nav>

        <Toast />
    </div>
</template>
