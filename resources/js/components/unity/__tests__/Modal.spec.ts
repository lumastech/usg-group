import { mount } from '@vue/test-utils';
import { nextTick } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';

import Modal from '../Modal.vue';

/**
 * A dialog whose content is taller than the viewport — the roles editor's
 * permission checklist is the worst case — must cap its own height and scroll
 * its body, so the header, the footer's actions, and the page behind it all
 * stay where they are.
 */
async function openModal(slots: Record<string, string> = {}) {
    const wrapper = mount(Modal, {
        props: { open: true, title: 'Edit role' },
        slots: { default: '<p>body</p>', ...slots },
        attachTo: document.body,
    });

    // ClientOnly renders its slot on mount, and the modal teleports to body.
    await nextTick();
    await nextTick();

    const panel = document.body.querySelector<HTMLElement>('[role="dialog"]');
    expect(panel).not.toBeNull();

    return { wrapper, panel: panel! };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('Modal', () => {
    it('caps the panel height and scrolls the body, not the panel', async () => {
        const { wrapper, panel } = await openModal();

        expect(panel.className).toContain('flex-col');
        expect(panel.className).toContain('max-h-[92dvh]');
        expect(panel.className).toContain('sm:max-h-[calc(100dvh-2rem)]');
        expect(panel.className).not.toContain('overflow-y-auto');

        const body = panel.querySelector<HTMLElement>(':scope > div')!;

        expect(body.className).toContain('overflow-y-auto');
        expect(body.className).toContain('min-h-0');
        expect(body.className).toContain('flex-1');
        expect(body.textContent?.trim()).toBe('body');

        wrapper.unmount();
    });

    it('keeps the header and footer out of the scrolling area', async () => {
        const { wrapper, panel } = await openModal({
            footer: '<button>Save</button>',
        });

        expect(panel.querySelector('header')!.className).toContain('shrink-0');
        expect(panel.querySelector('footer')!.className).toContain('shrink-0');

        wrapper.unmount();
    });
});
