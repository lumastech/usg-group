import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import MoneyInput from '../MoneyInput.vue';

/**
 * MoneyInput is the only place a user types money, so these tests pin the two
 * rules the rest of the system depends on: the model is always integer ngwee,
 * and a step (the constitution's K500 savings increment) is actually enforced.
 */
describe('MoneyInput', () => {
    /** Types into the field and blurs, which is when snapping happens. */
    async function type(wrapper: ReturnType<typeof mount>, value: string) {
        const input = wrapper.find('input');
        await input.trigger('focus');
        await input.setValue(value);
        await input.trigger('blur');

        return wrapper.props('modelValue' as never);
    }

    it('emits ngwee, not Kwacha', async () => {
        const wrapper = mount(MoneyInput, { props: { modelValue: null } });

        await type(wrapper, '1500');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([150_000]);
    });

    it('converts fractional Kwacha to whole ngwee', async () => {
        const wrapper = mount(MoneyInput, { props: { modelValue: null } });

        await type(wrapper, '1500.55');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([150_055]);
    });

    it('strips currency symbols and separators from pasted input', async () => {
        const wrapper = mount(MoneyInput, { props: { modelValue: null } });

        await type(wrapper, 'K1,250.00');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([125_000]);
    });

    it('snaps to the nearest step on blur', async () => {
        // K500 steps: K537 is not a legal savings amount and must round to K500.
        const wrapper = mount(MoneyInput, {
            props: { modelValue: null, step: 50_000 },
        });

        await type(wrapper, '537');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([50_000]);
    });

    it('rounds up to the nearer step', async () => {
        const wrapper = mount(MoneyInput, {
            props: { modelValue: null, step: 50_000 },
        });

        await type(wrapper, '760');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([100_000]);
    });

    it('leaves a value that already sits on a step untouched', async () => {
        const wrapper = mount(MoneyInput, {
            props: { modelValue: null, step: 50_000 },
        });

        await type(wrapper, '1500');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([150_000]);
    });

    it('does not snap while the user is still typing', async () => {
        const wrapper = mount(MoneyInput, {
            props: { modelValue: null, step: 50_000 },
        });

        const input = wrapper.find('input');
        await input.trigger('focus');
        await input.setValue('537');

        // Before blur the raw value stands, so typing "5375" stays possible.
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([53_700]);
    });

    it('clamps to the minimum', async () => {
        const wrapper = mount(MoneyInput, {
            props: { modelValue: null, min: 50_000 },
        });

        await type(wrapper, '100');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([50_000]);
    });

    it('clamps to the maximum', async () => {
        const wrapper = mount(MoneyInput, {
            props: { modelValue: null, max: 50_000 },
        });

        await type(wrapper, '900');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([50_000]);
    });

    it('emits null when cleared', async () => {
        const wrapper = mount(MoneyInput, { props: { modelValue: 150_000 } });

        await type(wrapper, '');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([null]);
    });

    it('steps up by the configured increment', async () => {
        const wrapper = mount(MoneyInput, {
            props: { modelValue: 150_000, step: 50_000 },
        });

        await wrapper
            .find('button[aria-label="Increase amount"]')
            .trigger('click');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([200_000]);
    });

    it('steps down by the configured increment', async () => {
        const wrapper = mount(MoneyInput, {
            props: { modelValue: 150_000, step: 50_000 },
        });

        await wrapper
            .find('button[aria-label="Decrease amount"]')
            .trigger('click');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([100_000]);
    });

    it('will not step below the minimum', async () => {
        const wrapper = mount(MoneyInput, {
            props: { modelValue: 50_000, step: 50_000, min: 50_000 },
        });

        expect(
            wrapper
                .find('button[aria-label="Decrease amount"]')
                .attributes('disabled'),
        ).toBeDefined();
    });

    it('displays the value formatted as Kwacha when not focused', () => {
        const wrapper = mount(MoneyInput, { props: { modelValue: 150_000 } });

        expect((wrapper.find('input').element as HTMLInputElement).value).toBe(
            '1,500.00',
        );
    });

    it('surfaces the step rule to the user', () => {
        const wrapper = mount(MoneyInput, {
            props: { modelValue: null, step: 50_000 },
        });

        expect(wrapper.text()).toContain('In steps of K500.00');
    });
});
