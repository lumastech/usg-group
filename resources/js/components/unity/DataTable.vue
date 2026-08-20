<script setup lang="ts" generic="T extends Record<string, unknown>">
/**
 * The portal's table. Sorting, filtering and pagination are server-side: each
 * change issues an Inertia partial reload of `only` props rather than refetching
 * the page, so a 30-member matrix stays quick on a phone.
 *
 * Row actions are gated by the `abilities` the server computed for each row, so a
 * button is never shown for something the policy would refuse.
 */
import { router } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    Download,
    Search,
} from '@lucide/vue';
import { computed, ref, useSlots, watch } from 'vue';

import { cn } from '@/lib/utils';
import AppButton from './AppButton.vue';
import EmptyState from './EmptyState.vue';

export type Column<Row> = {
    key: string;
    label: string;
    /** Enables the sort control; the server does the actual sorting. */
    sortable?: boolean;
    align?: 'left' | 'right' | 'center';
    /** Right-aligns and applies tabular figures. Use for money and counts. */
    numeric?: boolean;
    /** Hidden below md — use for secondary columns on the member portal. */
    hideOnMobile?: boolean;
    width?: string;
    /** Reads the display value when no #cell-<key> slot is given. */
    value?: (row: Row) => unknown;
};

export type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

const props = withDefaults(
    defineProps<{
        rows: T[];
        columns: Column<T>[];
        /** Laravel paginator meta. Omit for a non-paginated table. */
        meta?: PaginationMeta;
        /** Current sort, as shared by the server. */
        sort?: { column: string; direction: 'asc' | 'desc' } | null;
        search?: string;
        searchable?: boolean;
        searchPlaceholder?: string;
        /** Props to reload on interaction; keeps the visit to just the table data. */
        only?: string[];
        rowKey?: keyof T | ((row: T) => string | number);
        emptyTitle?: string;
        emptyDescription?: string;
        /** Shows the export button; the parent handles the actual download. */
        exportable?: boolean;
        loading?: boolean;
        class?: string;
    }>(),
    {
        searchPlaceholder: 'Search…',
        emptyTitle: 'Nothing to show',
    },
);

const emit = defineEmits<{ export: []; rowClick: [row: T] }>();

const searchTerm = ref(props.search ?? '');
let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.search,
    (value) => {
        searchTerm.value = value ?? '';
    },
);

/** Every interaction round-trips through Inertia so the server stays authoritative. */
function visit(params: Record<string, unknown>): void {
    router.get(
        window.location.pathname,
        params as Record<string, string | number | undefined>,
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: props.only,
        },
    );
}

function currentParams(): Record<string, unknown> {
    return {
        search: searchTerm.value || undefined,
        sort: props.sort?.column,
        direction: props.sort?.direction,
        page: props.meta?.current_page,
    };
}

function toggleSort(column: Column<T>): void {
    if (!column.sortable) {
        return;
    }

    const isCurrent = props.sort?.column === column.key;
    const direction =
        isCurrent && props.sort?.direction === 'asc' ? 'desc' : 'asc';

    visit({ ...currentParams(), sort: column.key, direction, page: 1 });
}

function onSearch(): void {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(
        () =>
            visit({
                ...currentParams(),
                search: searchTerm.value || undefined,
                page: 1,
            }),
        300,
    );
}

function goToPage(page: number): void {
    visit({ ...currentParams(), page });
}

/** Falls back to the row's `id`, then to its position, so a key always exists. */
function keyFor(row: T, index: number): string | number {
    if (typeof props.rowKey === 'function') {
        return props.rowKey(row);
    }

    const key = props.rowKey === undefined ? row.id : row[props.rowKey];

    return (key as string | number | undefined) ?? index;
}

function cellValue(row: T, column: Column<T>): unknown {
    return column.value ? column.value(row) : row[column.key];
}

function alignment(column: Column<T>): string {
    if (column.numeric || column.align === 'right') {
        return 'text-right';
    }

    return column.align === 'center' ? 'text-center' : 'text-left';
}

const pages = computed<number[]>(() => {
    if (!props.meta) {
        return [];
    }

    const { current_page: current, last_page: last } = props.meta;
    const span = 2;
    const from = Math.max(1, current - span);
    const to = Math.min(last, current + span);

    return Array.from({ length: to - from + 1 }, (_, index) => from + index);
});

const slots = useSlots();

const hasRows = computed(() => props.rows.length > 0);
const showToolbar = computed(
    () => props.searchable || props.exportable || !!slots.toolbar,
);
</script>

<template>
    <div
        :class="
            cn(
                'overflow-hidden rounded-xl border border-border bg-card shadow-card',
                $props.class,
            )
        "
    >
        <div
            v-if="showToolbar"
            class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3"
        >
            <div v-if="searchable" class="relative min-w-0 flex-1 sm:max-w-xs">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="searchTerm"
                    type="search"
                    :placeholder="searchPlaceholder"
                    class="h-9 w-full rounded-lg border border-input bg-background pr-3 pl-9 text-sm outline-none focus:border-brand-400 focus:ring-2 focus:ring-ring"
                    @input="onSearch"
                />
            </div>

            <div class="flex items-center gap-2">
                <slot name="toolbar" />
                <AppButton
                    v-if="exportable"
                    variant="outline"
                    size="sm"
                    @click="emit('export')"
                >
                    <template #icon><Download class="size-4" /></template>
                    Export
                </AppButton>
            </div>
        </div>

        <div class="scrollbar-thin overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-border bg-muted/50">
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :style="
                                column.width
                                    ? { width: column.width }
                                    : undefined
                            "
                            :class="
                                cn(
                                    'px-4 py-3 text-xs font-semibold tracking-wide text-muted-foreground uppercase',
                                    alignment(column),
                                    column.hideOnMobile &&
                                        'hidden md:table-cell',
                                )
                            "
                            :aria-sort="
                                sort?.column === column.key
                                    ? sort.direction === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : undefined
                            "
                        >
                            <button
                                v-if="column.sortable"
                                type="button"
                                class="inline-flex items-center gap-1 transition-colors hover:text-foreground"
                                @click="toggleSort(column)"
                            >
                                {{ column.label }}
                                <component
                                    :is="
                                        sort?.column === column.key
                                            ? sort.direction === 'asc'
                                                ? ArrowUp
                                                : ArrowDown
                                            : ChevronsUpDown
                                    "
                                    :class="
                                        cn(
                                            'size-3.5',
                                            sort?.column === column.key
                                                ? 'text-brand-600'
                                                : 'opacity-50',
                                        )
                                    "
                                />
                            </button>
                            <span v-else>{{ column.label }}</span>
                        </th>
                        <th
                            v-if="$slots.actions"
                            class="px-4 py-3 text-right text-xs font-semibold uppercase"
                        >
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody :class="loading && 'opacity-50 transition-opacity'">
                    <tr
                        v-for="(row, index) in rows"
                        :key="keyFor(row, index)"
                        class="border-b border-border/70 transition-colors last:border-0 hover:bg-accent/40"
                        @click="emit('rowClick', row)"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            :class="
                                cn(
                                    'px-4 py-3 text-card-foreground',
                                    alignment(column),
                                    column.numeric && 'tabular',
                                    column.hideOnMobile &&
                                        'hidden md:table-cell',
                                )
                            "
                        >
                            <slot
                                :name="`cell-${column.key}`"
                                :row="row"
                                :value="cellValue(row, column)"
                            >
                                {{ cellValue(row, column) ?? '—' }}
                            </slot>
                        </td>
                        <td
                            v-if="$slots.actions"
                            class="px-4 py-3 text-right whitespace-nowrap"
                        >
                            <slot name="actions" :row="row" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="!hasRows" class="border-t border-border">
            <slot name="empty">
                <EmptyState
                    :title="emptyTitle"
                    :description="emptyDescription"
                />
            </slot>
        </div>

        <div
            v-if="meta && meta.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3"
        >
            <p class="text-xs text-muted-foreground">
                Showing
                <span class="tabular font-medium"
                    >{{ meta.from ?? 0 }}–{{ meta.to ?? 0 }}</span
                >
                of
                <span class="tabular font-medium">{{ meta.total }}</span>
            </p>

            <nav class="flex items-center gap-1" aria-label="Pagination">
                <AppButton
                    variant="ghost"
                    size="sm"
                    :disabled="meta.current_page === 1"
                    @click="goToPage(meta.current_page - 1)"
                >
                    Previous
                </AppButton>
                <button
                    v-for="page in pages"
                    :key="page"
                    type="button"
                    :aria-current="
                        page === meta.current_page ? 'page' : undefined
                    "
                    :class="
                        cn(
                            'tabular size-8 rounded-md text-xs font-medium transition-colors',
                            page === meta.current_page
                                ? 'bg-brand-700 text-white'
                                : 'text-muted-foreground hover:bg-accent hover:text-foreground',
                        )
                    "
                    @click="goToPage(page)"
                >
                    {{ page }}
                </button>
                <AppButton
                    variant="ghost"
                    size="sm"
                    :disabled="meta.current_page === meta.last_page"
                    @click="goToPage(meta.current_page + 1)"
                >
                    Next
                </AppButton>
            </nav>
        </div>
    </div>
</template>
