import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

import DataTable from '../DataTable.vue';
import type { Column } from '../DataTable.vue';

// The table navigates through Inertia; stub the router so tests stay in-process.
const visit = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: {
        get: (...args: unknown[]) => visit(...args),
    },
}));

type Row = {
    id: number;
    member: string;
    savings: number;
    abilities: { edit: boolean; reverse: boolean };
};

const rows: Row[] = [
    {
        id: 1,
        member: 'Bertha Chileshe',
        savings: 450_000,
        abilities: { edit: true, reverse: false },
    },
    {
        id: 2,
        member: 'Gloria Kangwa',
        savings: 1_250_000,
        abilities: { edit: true, reverse: true },
    },
    {
        id: 3,
        member: 'Ireen Seta',
        savings: 300_000,
        abilities: { edit: false, reverse: false },
    },
];

const columns: Column<Row>[] = [
    { key: 'member', label: 'Member', sortable: true },
    { key: 'savings', label: 'Savings', numeric: true, sortable: true },
];

/**
 * DataTable is generic over its row type, and `mount` cannot carry that generic
 * through. This narrows the props once so the specs stay readable.
 */
function mountTable(
    props: Record<string, unknown>,
    options: Record<string, unknown> = {},
) {
    return mount(DataTable as never, { props, ...options } as never);
}

function table(props: Record<string, unknown> = {}) {
    return mountTable(
        { rows, columns, ...props },
        {
            slots: {
                // Mirrors how module screens gate actions: purely on server-sent abilities.
                actions: `
                <template #default="{ row }">
                    <button v-if="row.abilities.edit" class="edit">Edit</button>
                    <button v-if="row.abilities.reverse" class="reverse">Reverse</button>
                </template>
            `,
            },
        },
    );
}

describe('DataTable', () => {
    it('renders a row per record', () => {
        expect(table().findAll('tbody tr')).toHaveLength(3);
    });

    it('renders row actions only where the ability is granted', () => {
        const wrapper = table();

        // Two rows may be edited, one of those may also be reversed.
        expect(wrapper.findAll('button.edit')).toHaveLength(2);
        expect(wrapper.findAll('button.reverse')).toHaveLength(1);
    });

    it('hides every action on a row with no abilities', () => {
        const wrapper = table();
        const lastRow = wrapper.findAll('tbody tr')[2];

        expect(lastRow.find('button.edit').exists()).toBe(false);
        expect(lastRow.find('button.reverse').exists()).toBe(false);
    });

    it('does not render the actions column when no actions slot is given', () => {
        const wrapper = mountTable({ rows, columns });

        expect(wrapper.find('button.edit').exists()).toBe(false);
        expect(wrapper.findAll('thead th')).toHaveLength(columns.length);
    });

    it('applies tabular figures to numeric columns so money aligns', () => {
        const wrapper = table();
        const cells = wrapper.findAll('tbody tr:first-child td');

        expect(cells[1].classes()).toContain('tabular');
        expect(cells[0].classes()).not.toContain('tabular');
    });

    it('shows an empty state instead of a bare table', () => {
        const wrapper = mountTable({
            rows: [],
            columns,
            emptyTitle: 'No members yet',
        });

        expect(wrapper.text()).toContain('No members yet');
    });

    it('marks the sorted column for assistive technology', () => {
        const wrapper = mountTable({
            rows,
            columns,
            sort: { column: 'member', direction: 'asc' },
        });

        expect(wrapper.findAll('thead th')[0].attributes('aria-sort')).toBe(
            'ascending',
        );
        expect(
            wrapper.findAll('thead th')[1].attributes('aria-sort'),
        ).toBeUndefined();
    });

    it('asks the server to sort rather than sorting in the browser', async () => {
        visit.mockClear();

        const wrapper = mountTable({
            rows,
            columns,
            sort: { column: 'member', direction: 'asc' },
            only: ['members'],
        });

        await wrapper.findAll('thead th button')[0].trigger('click');

        expect(visit).toHaveBeenCalledOnce();
        expect(visit.mock.calls[0][1]).toMatchObject({
            sort: 'member',
            direction: 'desc',
            page: 1,
        });
        // Only the table's own props are refetched, not the whole page.
        expect(visit.mock.calls[0][2]).toMatchObject({ only: ['members'] });
    });

    it('renders pagination only when there is more than one page', () => {
        const single = mountTable({
            rows,
            columns,
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 15,
                total: 3,
                from: 1,
                to: 3,
            },
        });
        expect(single.find('nav[aria-label="Pagination"]').exists()).toBe(
            false,
        );

        const many = mountTable({
            rows,
            columns,
            meta: {
                current_page: 1,
                last_page: 4,
                per_page: 3,
                total: 12,
                from: 1,
                to: 3,
            },
        });
        expect(many.find('nav[aria-label="Pagination"]').exists()).toBe(true);
    });

    it('emits export rather than building the file itself', async () => {
        const wrapper = mountTable({ rows, columns, exportable: true });

        await wrapper.find('button').trigger('click');

        expect(wrapper.emitted('export')).toHaveLength(1);
    });
});
