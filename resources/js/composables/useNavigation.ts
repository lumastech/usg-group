import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import { usePermissions } from '@/composables/usePermissions';
import { adminNavigation, memberNavigation } from '@/config/navigation';
import type { NavItem, NavSection } from '@/config/navigation';

/**
 * Filters the navigation config down to what the signed-in user may reach.
 *
 * Both shells read from here, so the sidebar and the member bottom-nav can never
 * drift apart. Sections that end up empty are dropped rather than left as headings.
 */
export function useNavigation() {
    const page = usePage();
    const { canAny, canAll } = usePermissions();

    function isVisible(item: NavItem): boolean {
        if (!item.permissions?.length) {
            return true;
        }

        return item.requireAll
            ? canAll(item.permissions)
            : canAny(item.permissions);
    }

    const currentPath = computed<string>(
        () => new URL(page.url, 'http://localhost').pathname,
    );

    /** Exact items match the path only; others also light up on their child routes. */
    function isActive(item: NavItem): boolean {
        const path = currentPath.value.replace(/\/$/, '') || '/';
        const href = item.href.replace(/\/$/, '') || '/';

        return item.exact
            ? path === href
            : path === href || path.startsWith(`${href}/`);
    }

    const adminSections = computed<NavSection[]>(() =>
        adminNavigation
            .map((section) => ({
                ...section,
                items: section.items.filter(isVisible),
            }))
            .filter((section) => section.items.length > 0),
    );

    const memberItems = computed<NavItem[]>(() =>
        memberNavigation.filter(isVisible),
    );

    return { adminSections, memberItems, isActive, isVisible, currentPath };
}
