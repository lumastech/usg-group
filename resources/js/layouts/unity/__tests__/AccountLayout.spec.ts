import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import AccountLayout from '../AccountLayout.vue';

// useCurrentUrl grabs the page object once, at module scope, so the mock has to
// hand back a reactive one the tests can then drive by changing the URL.
const inertia = vi.hoisted(() => ({
    page: null as unknown as {
        url: string;
        props: {
            auth: { user: { roles: string[]; permissions: string[] } };
            currentCycle: null;
        };
    },
}));

// The layout only needs Inertia's page state and a link; the shells themselves
// are stubbed below so the test can assert which one was chosen.
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual<typeof import('vue')>('vue');

    inertia.page = reactive({
        url: '/settings/profile',
        props: {
            auth: {
                user: {
                    id: 1,
                    name: 'Bertha Phiri',
                    member_id: 9,
                    roles: ['member'],
                    permissions: [],
                },
            },
            currentCycle: null,
        },
    });

    return {
        usePage: () => inertia.page,
        Link: {
            props: ['href'],
            template:
                '<a :href="typeof href === \'string\' ? href : href.url"><slot /></a>',
        },
        Head: { template: '<div />' },
    };
});

vi.mock('@/layouts/unity/AdminLayout.vue', () => ({
    default: { template: '<div data-shell="admin"><slot /></div>' },
}));

vi.mock('@/layouts/unity/MemberLayout.vue', () => ({
    default: { template: '<div data-shell="member"><slot /></div>' },
}));

function mountLayout() {
    return mount(AccountLayout, {
        slots: { default: '<p>page body</p>' },
    });
}

describe('AccountLayout', () => {
    beforeEach(() => {
        inertia.page.url = '/settings/profile';
        inertia.page.props.auth.user.roles = ['member'];
    });

    it('links to every account screen', () => {
        const hrefs = mountLayout()
            .findAll('nav a')
            .map((link) => link.attributes('href'));

        expect(hrefs).toEqual([
            '/settings/profile',
            '/settings/security',
            '/settings/appearance',
        ]);
    });

    it('marks the screen being viewed as current', () => {
        inertia.page.url = '/settings/security';

        const current = mountLayout()
            .findAll('nav a')
            .filter((link) => link.attributes('aria-current') === 'page');

        expect(current).toHaveLength(1);
        expect(current[0].attributes('href')).toBe('/settings/security');
    });

    it('keeps a member in the member shell', () => {
        const wrapper = mountLayout();

        expect(wrapper.find('[data-shell="member"]').exists()).toBe(true);
        expect(wrapper.html()).toContain('page body');
    });

    it('keeps a committee member in the committee shell', () => {
        inertia.page.props.auth.user.roles = ['treasurer', 'member'];

        expect(mountLayout().find('[data-shell="admin"]').exists()).toBe(true);
    });
});
