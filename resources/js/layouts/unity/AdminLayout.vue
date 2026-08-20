<script setup lang="ts">
/**
 * The committee shell: dark sidebar, light content. Desktop-first but the sidebar
 * collapses to a drawer below lg, since committee members also carry phones.
 *
 * Navigation is rendered from the shared config filtered by permission, so this
 * layout never hard-codes a link.
 */
import { Head, Link, usePage } from '@inertiajs/vue3';
import { LogOut, Menu, X } from '@lucide/vue';
import { ref, watch } from 'vue';

import { Toast } from '@/components/unity';
import { useNavigation } from '@/composables/useNavigation';
import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/utils';
import CycleBanner from './CycleBanner.vue';
import PortalSwitcher from './PortalSwitcher.vue';

defineProps<{ title?: string; heading?: string; description?: string }>();

const page = usePage();
const { adminSections, isActive } = useNavigation();
const { user, currentCycle, primaryRoleLabel } = usePermissions();

const drawerOpen = ref(false);

/** Close the mobile drawer whenever the user navigates. */
watch(
    () => page.url,
    () => (drawerOpen.value = false),
);
</script>

<template>
    <Head :title="title" />

    <div class="min-h-svh bg-background">
        <!-- Sidebar: fixed on desktop, a drawer under lg. -->
        <aside
            :class="
                cn(
                    'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-sidebar transition-transform duration-200 lg:translate-x-0',
                    drawerOpen ? 'translate-x-0' : '-translate-x-full',
                )
            "
        >
            <div class="flex h-16 items-center justify-between gap-2 px-5">
                <Link href="/app" class="flex min-w-0 items-center gap-2.5">
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-lg bg-gold-400 text-sm font-bold text-brand-950"
                    >
                        U
                    </span>
                    <span class="min-w-0">
                        <span
                            class="block truncate text-sm font-semibold text-sidebar-foreground"
                            >Unity Savings</span
                        >
                        <span
                            class="block truncate text-[0.6875rem] text-sidebar-foreground/60"
                        >
                            {{ currentCycle?.name ?? 'No active cycle' }}
                        </span>
                    </span>
                </Link>

                <button
                    type="button"
                    class="grid size-8 place-items-center rounded-lg text-sidebar-foreground/70 hover:bg-sidebar-accent lg:hidden"
                    aria-label="Close menu"
                    @click="drawerOpen = false"
                >
                    <X class="size-4" />
                </button>
            </div>

            <nav
                class="flex-1 scrollbar-thin space-y-6 overflow-y-auto px-3 py-4"
            >
                <div v-for="section in adminSections" :key="section.label">
                    <p
                        class="px-3 pb-2 text-[0.6875rem] font-semibold tracking-wider text-sidebar-foreground/45 uppercase"
                    >
                        {{ section.label }}
                    </p>
                    <ul class="space-y-0.5">
                        <li v-for="item in section.items" :key="item.href">
                            <Link
                                :href="item.href"
                                :class="
                                    cn(
                                        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                                        isActive(item)
                                            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                            : 'text-sidebar-foreground/75 hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground',
                                    )
                                "
                                :aria-current="
                                    isActive(item) ? 'page' : undefined
                                "
                            >
                                <component
                                    :is="item.icon"
                                    :class="
                                        cn(
                                            'size-4 shrink-0',
                                            isActive(item) && 'text-gold-400',
                                        )
                                    "
                                />
                                <span class="truncate">{{ item.title }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="space-y-1 border-t border-sidebar-border p-3">
                <PortalSwitcher tone="dark" class="w-full" />
                <div class="flex items-center gap-3 rounded-lg px-3 py-2">
                    <span
                        class="grid size-8 shrink-0 place-items-center rounded-full bg-sidebar-accent text-xs font-semibold text-sidebar-accent-foreground"
                    >
                        {{ user?.name?.charAt(0) ?? '?' }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-xs font-medium text-sidebar-foreground"
                            >{{ user?.name }}</span
                        >
                        <span
                            class="block truncate text-[0.6875rem] text-sidebar-foreground/55"
                        >
                            {{ primaryRoleLabel ?? 'Member' }}
                        </span>
                    </span>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        class="grid size-8 shrink-0 place-items-center rounded-lg text-sidebar-foreground/60 transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
                        aria-label="Log out"
                    >
                        <LogOut class="size-4" />
                    </Link>
                </div>
            </div>
        </aside>

        <div
            v-if="drawerOpen"
            class="fixed inset-0 z-30 bg-brand-950/50 lg:hidden"
            @click="drawerOpen = false"
        />

        <div class="lg:pl-64">
            <header
                class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-border bg-background/85 px-4 backdrop-blur-sm sm:px-6"
            >
                <button
                    type="button"
                    class="grid size-9 shrink-0 place-items-center rounded-lg text-muted-foreground hover:bg-accent lg:hidden"
                    aria-label="Open menu"
                    @click="drawerOpen = true"
                >
                    <Menu class="size-5" />
                </button>

                <div class="min-w-0 flex-1">
                    <h1
                        v-if="heading"
                        class="truncate text-base font-semibold tracking-tight text-foreground"
                    >
                        {{ heading }}
                    </h1>
                    <p
                        v-if="description"
                        class="truncate text-xs text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <slot name="actions" />
                </div>
            </header>

            <main class="px-4 py-6 sm:px-6 lg:px-8">
                <CycleBanner class="mb-5" />
                <slot />
            </main>
        </div>

        <Toast />
    </div>
</template>
