<script setup lang="ts">
/**
 * Renders its slot only when the user holds the required permission(s).
 *
 *   <Can permission="loans.approve">…</Can>
 *   <Can :any="['loans.approve', 'loans.disburse']">…</Can>
 *   <Can :all="['loans.approve', 'fund.approve-outflow']">…</Can>
 *
 * A #fallback slot renders instead when the check fails. This hides UI; it does
 * not secure anything — the route behind the button is what enforces access.
 */
import { computed } from 'vue';

import { usePermissions } from '@/composables/usePermissions';
import type { MemberRoleName, PermissionName } from '@/types/auth';

const props = defineProps<{
    permission?: PermissionName;
    any?: PermissionName[];
    all?: PermissionName[];
    role?: MemberRoleName;
    /** Invert the result, e.g. show something only to users who lack a permission. */
    unless?: boolean;
}>();

const { can, canAny, canAll, hasRole } = usePermissions();

const allowed = computed<boolean>(() => {
    const checks: boolean[] = [];

    if (props.permission) {
        checks.push(can(props.permission));
    }

    if (props.any?.length) {
        checks.push(canAny(props.any));
    }

    if (props.all?.length) {
        checks.push(canAll(props.all));
    }

    if (props.role) {
        checks.push(hasRole(props.role));
    }

    // With no conditions given, render — a <Can> with no props is a no-op wrapper.
    const passes = checks.length === 0 || checks.every(Boolean);

    return props.unless ? !passes : passes;
});
</script>

<template>
    <slot v-if="allowed" />
    <slot v-else name="fallback" />
</template>
