<script setup lang="ts">
/**
 * The shell for the account screens at /settings/*.
 *
 * These pages came from the starter kit and used to render in its own sidebar, so
 * nothing in either portal linked to them and the group could not change a
 * password or an email address. They now sit inside whichever portal shell the
 * user belongs to — committee members keep the sidebar, everyone else keeps the
 * bottom nav — with a tab strip across the three account screens.
 *
 * The page bodies still use the starter kit's shadcn primitives; only the shell
 * around them is Unity's.
 */
import { Link } from '@inertiajs/vue3';
import { KeyRound, Palette, UserRound } from '@lucide/vue';
import { computed } from 'vue';

import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { usePermissions } from '@/composables/usePermissions';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import { cn } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';

const { isCommittee } = usePermissions();
const { isCurrentOrParentUrl } = useCurrentUrl();

/** A committee member's home is /app, so keep them in the sidebar shell. */
const shell = computed(() => (isCommittee.value ? AdminLayout : MemberLayout));

const tabs = [
    {
        title: 'Profile',
        href: editProfile(),
        icon: UserRound,
        description: 'Your name and email address',
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: KeyRound,
        description: 'Password, two-factor and passkeys',
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
        description: 'How the portal looks on this device',
    },
];

const activeTab = computed(() =>
    tabs.find((tab) => isCurrentOrParentUrl(tab.href)),
);
</script>

<template>
    <component
        :is="shell"
        heading="Account"
        :description="activeTab?.description ?? 'Your login and preferences'"
    >
        <div class="mx-auto w-full max-w-2xl space-y-5">
            <nav
                class="flex gap-1 rounded-xl border border-border bg-card p-1 shadow-card"
                aria-label="Account settings"
            >
                <Link
                    v-for="tab in tabs"
                    :key="tab.title"
                    :href="tab.href"
                    :class="
                        cn(
                            'flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                            isCurrentOrParentUrl(tab.href)
                                ? 'bg-brand-700 text-white'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        )
                    "
                    :aria-current="
                        isCurrentOrParentUrl(tab.href) ? 'page' : undefined
                    "
                >
                    <component :is="tab.icon" class="size-4 shrink-0" />
                    <span class="truncate">{{ tab.title }}</span>
                </Link>
            </nav>

            <slot />
        </div>
    </component>
</template>
