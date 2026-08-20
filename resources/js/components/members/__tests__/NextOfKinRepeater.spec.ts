import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { reactive } from 'vue';

import { NextOfKinRelationship } from '@/types/enums';
import type { NextOfKinDraft } from '@/types/members';
import NextOfKinRepeater from '../NextOfKinRepeater.vue';

const relationships = [
    { value: NextOfKinRelationship.Spouse, label: 'Spouse' },
    { value: NextOfKinRelationship.Sibling, label: 'Sibling' },
    { value: NextOfKinRelationship.Other, label: 'Other' },
];

function row(overrides: Partial<NextOfKinDraft> = {}): NextOfKinDraft {
    return {
        name: 'Pamela Kashweka',
        phone: '0977496538',
        relationship: NextOfKinRelationship.Sibling,
        relationship_label: 'Sister',
        ...overrides,
    };
}

/** The rows are reactive so the component sees the same mutations a form would. */
function mountRepeater(
    rows: NextOfKinDraft[],
    errors: Record<string, string> = {},
) {
    return mount(NextOfKinRepeater, {
        props: {
            modelValue: rows,
            relationships,
            errors,
            'onUpdate:modelValue': (value: NextOfKinDraft[]) =>
                rows.splice(0, rows.length, ...value),
        },
    });
}

describe('NextOfKinRepeater', () => {
    it('renders one block per nominated person', () => {
        const wrapper = mountRepeater([
            row(),
            row({ name: 'Nobya Situmbeko' }),
        ]);

        expect(wrapper.text()).toContain('Next of kin 1');
        expect(wrapper.text()).toContain('Next of kin 2');
    });

    it('adds a row and removes the right one', async () => {
        const rows = [row({ name: 'First' }), row({ name: 'Second' })];
        const wrapper = mountRepeater(rows);

        await wrapper.find('button[aria-label="Remove"]').trigger('click');

        expect(rows.map((entry) => entry.name)).toEqual(['Second']);

        const addButton = wrapper.findAll('button').at(-1)!;
        await addButton.trigger('click');

        expect(rows).toHaveLength(2);
        expect(rows[1].name).toBe('');
    });

    it('stops adding rows once the maximum is reached', async () => {
        const rows = [row(), row()];
        const wrapper = mountRepeater(rows);

        expect(wrapper.props('max')).toBe(5);
        expect(wrapper.findAll('button').at(-1)!.text()).toContain(
            'Add next of kin',
        );

        const capped = mountRepeater([row(), row()], {});
        await capped.setProps({ max: 2 });

        expect(capped.text()).not.toContain('Add next of kin');
    });

    it('only asks how someone is related when the bucket is Other', async () => {
        const rows = reactive([
            row({ relationship: NextOfKinRelationship.Sibling }),
        ]);
        const wrapper = mountRepeater(rows);

        expect(wrapper.text()).not.toContain('How are they related?');

        rows[0].relationship = NextOfKinRelationship.Other;
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('How are they related?');
    });

    it('shows the server error against the row it belongs to', () => {
        const wrapper = mountRepeater([row(), row({ name: '' })], {
            'next_of_kin.1.name':
                'Give the name of each next of kin, or remove the row.',
        });

        expect(wrapper.text()).toContain(
            'Give the name of each next of kin, or remove the row.',
        );
    });
});
