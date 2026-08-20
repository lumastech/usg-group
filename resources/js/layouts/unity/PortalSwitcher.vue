<script setup lang="ts">
/**
 * Switches a committee member between the two portals.
 *
 * Most committee members are also ordinary members, so they need both the
 * committee tools at /app and their own savings at /my. Members without a
 * committee role never see this — they only have one portal.
 */
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowLeftRight } from '@lucide/vue';
import { computed } from 'vue';

import { usePermissions } from '@/composables/usePermissions';
import { cn } from '@/lib/utils';

const props = withDefaults(defineProps<{ tone?: 'dark' | 'light' }>(), {
    tone: 'light',
});

const page = usePage();
const { isCommittee, isMember } = usePermissions();

const inMemberPortal = computed(() => page.url.startsWith('/my'));

/** Only useful to someone who genuinely has both portals available. */
const visible = computed(() => isCommittee.value && isMember.value);

const target = computed(() => (inMemberPortal.value ? '/app' : '/my'));
const label = computed(() =>
    inMemberPortal.value ? 'Committee view' : 'My savings',
);
</script>

<template>
    <Link
        v-if="visible"
        :href="target"
        :class="
            cn(
                'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium transition-colors',
                props.tone === 'dark'
                    ? 'text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'
                    : 'text-muted-foreground hover:bg-accent hover:text-foreground',
            )
        "
    >
        <ArrowLeftRight class="size-4" />
        <span>{{ label }}</span>
    </Link>
</template>
