import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import type {
    CurrentCycle,
    MemberRoleName,
    PermissionName,
    User,
} from '@/types/auth';

/**
 * Roles that see the committee portal at /app, most senior first.
 *
 * Order matters: nearly every committee member also holds `member`, so the label
 * shown in the UI must be the senior office rather than whichever role happened
 * to be assigned first.
 */
const COMMITTEE_ROLES: MemberRoleName[] = [
    'admin',
    'chairperson',
    'vice_chairperson',
    'treasurer',
    'vice_treasurer',
];

/** Display names for the offices, matching MemberRole::label() on the backend. */
const ROLE_LABELS: Record<MemberRoleName, string> = {
    admin: 'Administrator',
    chairperson: 'Chairperson',
    vice_chairperson: 'Vice-Chairperson',
    treasurer: 'Treasurer',
    vice_treasurer: 'Vice-Treasurer',
    member: 'Member',
};

/**
 * Reads the permissions the backend shared for the signed-in user.
 *
 * This is presentation only: it decides what to render, never what is allowed.
 * The server re-checks every action through a policy or permission middleware, so
 * a user who forges a permission here still gets a 403 from the route.
 */
export function usePermissions() {
    const page = usePage();

    const user = computed<User | null>(() => page.props.auth?.user ?? null);
    const currentCycle = computed<CurrentCycle | null>(
        () => page.props.currentCycle ?? null,
    );

    const permissions = computed<PermissionName[]>(
        () => user.value?.permissions ?? [],
    );
    const roles = computed<MemberRoleName[]>(() => user.value?.roles ?? []);

    /** True when the user holds this permission. */
    function can(permission: PermissionName): boolean {
        return permissions.value.includes(permission);
    }

    /** True when the user holds at least one of these permissions. */
    function canAny(wanted: PermissionName[]): boolean {
        return wanted.some((permission) => can(permission));
    }

    /** True when the user holds every one of these permissions. */
    function canAll(wanted: PermissionName[]): boolean {
        return wanted.every((permission) => can(permission));
    }

    function hasRole(role: MemberRoleName): boolean {
        return roles.value.includes(role);
    }

    function hasAnyRole(wanted: MemberRoleName[]): boolean {
        return wanted.some((role) => hasRole(role));
    }

    /** Committee and admins get the /app portal; everyone else stays in /my. */
    const isCommittee = computed<boolean>(() => hasAnyRole(COMMITTEE_ROLES));

    /** Whether this user has a member record in the current cycle. */
    const isMember = computed<boolean>(() => user.value?.member_id != null);

    /** The most senior office the user holds, falling back to plain membership. */
    const primaryRole = computed<MemberRoleName | null>(() => {
        const senior = COMMITTEE_ROLES.find((role) => hasRole(role));

        return senior ?? (roles.value.length > 0 ? 'member' : null);
    });

    /** That office's display name, e.g. "Vice-Treasurer". */
    const primaryRoleLabel = computed<string | null>(() =>
        primaryRole.value ? ROLE_LABELS[primaryRole.value] : null,
    );

    return {
        user,
        currentCycle,
        permissions,
        roles,
        can,
        canAny,
        canAll,
        hasRole,
        hasAnyRole,
        isCommittee,
        isMember,
        primaryRole,
        primaryRoleLabel,
    };
}
