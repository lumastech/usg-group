import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import WindowCountdown from '../WindowCountdown.vue';

/**
 * The countdown is anchored to the seconds the server sent, not to a target
 * timestamp, so a member whose handset clock is a day out still sees the right
 * answer. These tests pin that, and the formatting the banner reads by.
 */
describe('WindowCountdown', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('names the state the month is in', () => {
        const wrapper = mount(WindowCountdown, {
            props: { window: 'declarations', secondsRemaining: 3_600 },
        });

        expect(wrapper.text()).toContain('Declarations are open');
        expect(wrapper.text()).toContain('left to declare');
    });

    it('counts minutes and seconds near the wire', () => {
        const wrapper = mount(WindowCountdown, {
            props: { window: 'declarations', secondsRemaining: 90 },
        });

        expect(wrapper.text()).toContain('1m 30s');
    });

    it('counts days and hours when there is a while to go', () => {
        const wrapper = mount(WindowCountdown, {
            props: { window: 'before_declarations', secondsRemaining: 180_000 },
        });

        expect(wrapper.text()).toContain('2d 2h');
    });

    it('ticks down from the seconds the server sent', async () => {
        const wrapper = mount(WindowCountdown, {
            props: { window: 'trading', secondsRemaining: 65 },
        });

        vi.advanceTimersByTime(5_000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('1m 0s');
    });

    it('never counts past zero', async () => {
        const wrapper = mount(WindowCountdown, {
            props: { window: 'declarations', secondsRemaining: 2 },
        });

        vi.advanceTimersByTime(10_000);
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('0m 0s');
    });

    it('shows no countdown once the month is closed', () => {
        const wrapper = mount(WindowCountdown, {
            props: { window: 'closed', secondsRemaining: null },
        });

        expect(wrapper.text()).toContain('This month is closed');
        expect(wrapper.text()).not.toMatch(/\d+[dhms]/);
    });
});
