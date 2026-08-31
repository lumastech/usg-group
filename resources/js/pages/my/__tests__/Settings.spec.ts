import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

import Settings from '../Settings.vue';

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) =>
        reactive({ ...initial, errors: {}, processing: false, put: vi.fn() }),
    Link: {
        props: ['href'],
        template:
            '<a :href="typeof href === \'string\' ? href : href.url"><slot /></a>',
    },
}));

vi.mock('@/layouts/unity/MemberLayout.vue', () => ({
    default: { template: '<div><slot /></div>' },
}));

const member = {
    id: 9,
    full_name: 'Bertha Phiri',
    phone: '0977433571',
    email: 'bertha@example.com',
    notification_channel: 'mail',
};

function mountPage(withMember: boolean = true) {
    return mount(Settings, {
        props: {
            member: withMember ? member : null,
            channels: [{ value: 'mail', label: 'Email' }],
            effective: ['mail'],
        },
    });
}

function accountLinks(wrapper: ReturnType<typeof mountPage>): string[] {
    return wrapper
        .findAll('a')
        .map((link) => link.attributes('href') ?? '')
        .filter((href) => href.startsWith('/settings'));
}

describe('my/Settings', () => {
    it('offers a way into the account screens', () => {
        expect(accountLinks(mountPage())).toEqual([
            '/settings/profile',
            '/settings/security',
            '/settings/appearance',
        ]);
    });

    it('still offers them to a login with no member record', () => {
        // Someone waiting to be linked to the register still has a password to
        // change, so the card sits outside the member-only branch.
        expect(accountLinks(mountPage(false))).toHaveLength(3);
    });
});
