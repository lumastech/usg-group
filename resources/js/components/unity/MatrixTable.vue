<script setup lang="ts" generic="Row extends Record<string, unknown>">
/**
 * The workbook-style month matrix: members down the side, months across the top.
 *
 * The first column and the header row both stay pinned while the body scrolls
 * horizontally, which is what makes a 12-month grid readable on a phone. Column
 * totals pin to the bottom when `totals` is supplied.
 */
import { computed } from 'vue';

import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/utils';

export type MatrixColumn = {
    key: string;
    label: string;
    /** Second line in the header, e.g. the year under the month name. */
    sublabel?: string;
    /** Dims the column — used for months outside the trading window. */
    muted?: boolean;
    /** Marks the column as the current month. */
    current?: boolean;
};

const props = withDefaults(
    defineProps<{
        rows: Row[];
        columns: MatrixColumn[];
        /** Header for the pinned first column. */
        rowHeader?: string;
        /** Reads the pinned first-column label from a row. */
        rowLabel: (row: Row) => string;
        /** Optional second line under the row label, e.g. a member number. */
        rowSublabel?: (row: Row) => string | undefined;
        /** Reads a cell as ngwee. Return null to render as empty. */
        cell: (row: Row, column: MatrixColumn) => number | null;
        /** Per-column totals in ngwee, keyed by column key. */
        totals?: Record<string, number>;
        /** Row totals in ngwee, shown in a pinned trailing column. */
        rowTotal?: (row: Row) => number;
        rowTotalLabel?: string;
        rowKey: (row: Row) => string | number;
        class?: string;
    }>(),
    { rowHeader: 'Member', rowTotalLabel: 'Total' },
);

const emit = defineEmits<{ cellClick: [row: Row, column: MatrixColumn] }>();

const hasTotals = computed(() => props.totals !== undefined);
const hasRowTotal = computed(() => props.rowTotal !== undefined);

const grandTotal = computed<number>(() => {
    if (!props.rowTotal) {
        return 0;
    }

    return props.rows.reduce(
        (sum, row) => sum + (props.rowTotal?.(row) ?? 0),
        0,
    );
});

function display(value: number | null): string {
    return value === null ? '—' : formatMoney(value, { symbol: false });
}
</script>

<template>
    <div
        :class="
            cn(
                'relative scrollbar-thin overflow-auto rounded-xl border border-border bg-card shadow-card',
                $props.class,
            )
        "
    >
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr>
                    <th
                        scope="col"
                        class="sticky top-0 left-0 z-30 min-w-44 border-r border-b border-border bg-muted px-4 py-3 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ rowHeader }}
                    </th>
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        :class="
                            cn(
                                'sticky top-0 z-20 min-w-28 border-b border-border bg-muted px-3 py-3 text-right text-xs font-semibold tracking-wide uppercase',
                                column.current
                                    ? 'text-brand-700 dark:text-brand-300'
                                    : 'text-muted-foreground',
                                column.muted && 'opacity-60',
                            )
                        "
                    >
                        <span class="block">{{ column.label }}</span>
                        <span
                            v-if="column.sublabel"
                            class="block text-[0.625rem] font-normal normal-case opacity-70"
                        >
                            {{ column.sublabel }}
                        </span>
                    </th>
                    <th
                        v-if="hasRowTotal"
                        scope="col"
                        class="sticky top-0 right-0 z-30 min-w-32 border-b border-l border-border bg-muted px-4 py-3 text-right text-xs font-semibold tracking-wide text-foreground uppercase"
                    >
                        {{ rowTotalLabel }}
                    </th>
                </tr>
            </thead>

            <tbody>
                <tr v-for="row in rows" :key="rowKey(row)" class="group">
                    <th
                        scope="row"
                        class="sticky left-0 z-10 border-r border-b border-border bg-card px-4 py-2.5 text-left font-medium text-card-foreground group-hover:bg-accent/40"
                    >
                        <span class="block truncate">{{ rowLabel(row) }}</span>
                        <span
                            v-if="rowSublabel?.(row)"
                            class="block text-xs font-normal text-muted-foreground"
                        >
                            {{ rowSublabel(row) }}
                        </span>
                    </th>

                    <td
                        v-for="column in columns"
                        :key="column.key"
                        :class="
                            cn(
                                'tabular border-b border-border/70 px-3 py-2.5 text-right text-card-foreground group-hover:bg-accent/40',
                                column.muted && 'text-muted-foreground',
                                column.current &&
                                    'bg-brand-50/60 dark:bg-brand-400/5',
                                cell(row, column) === null &&
                                    'text-muted-foreground/50',
                            )
                        "
                        @click="emit('cellClick', row, column)"
                    >
                        <slot
                            name="cell"
                            :row="row"
                            :column="column"
                            :value="cell(row, column)"
                        >
                            {{ display(cell(row, column)) }}
                        </slot>
                    </td>

                    <td
                        v-if="hasRowTotal"
                        class="tabular sticky right-0 z-10 border-b border-l border-border bg-card px-4 py-2.5 text-right font-semibold text-card-foreground group-hover:bg-accent/40"
                    >
                        {{ formatMoney(rowTotal!(row), { symbol: false }) }}
                    </td>
                </tr>
            </tbody>

            <tfoot v-if="hasTotals">
                <tr>
                    <th
                        scope="row"
                        class="sticky bottom-0 left-0 z-30 border-t-2 border-r border-border bg-muted px-4 py-3 text-left text-xs font-semibold tracking-wide text-foreground uppercase"
                    >
                        Total
                    </th>
                    <td
                        v-for="column in columns"
                        :key="column.key"
                        :class="
                            cn(
                                'tabular sticky bottom-0 z-20 border-t-2 border-border bg-muted px-3 py-3 text-right font-semibold text-foreground',
                                column.muted && 'opacity-60',
                            )
                        "
                    >
                        {{
                            formatMoney(totals?.[column.key] ?? 0, {
                                symbol: false,
                            })
                        }}
                    </td>
                    <td
                        v-if="hasRowTotal"
                        class="tabular sticky right-0 bottom-0 z-30 border-t-2 border-l border-border bg-muted px-4 py-3 text-right font-bold text-foreground"
                    >
                        {{ formatMoney(grandTotal, { symbol: false }) }}
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
