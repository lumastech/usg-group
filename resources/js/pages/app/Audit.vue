<script setup lang="ts">
/**
 * The audit trail.
 *
 * Every financial mutation is activity-logged and the ledgers are immutable, so
 * this is not a debugging screen — it is how the chair holds the committee to
 * account. Filtering is server-side like everywhere else; a row expands to show the
 * before/after the log actually recorded rather than a paraphrase of it.
 */
import { router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, ScrollText } from '@lucide/vue';
import { ref } from 'vue';

import {
    AppCard,
    DataTable,
    SelectInput,
    StatusBadge,
    TextInput,
} from '@/components/unity';
import type { Column, PaginationMeta } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';

type AuditRow = {
    id: number;
    description: string;
    log_name: string | null;
    event: string | null;
    causer: { id: number; name: string } | null;
    subject_type: string | null;
    subject_label: string | null;
    subject_id: number | null;
    properties: Record<string, unknown>;
    created_at: string | null;
};

type Option = { value: string | number; label: string };

const props = defineProps<{
    activities: { data: AuditRow[]; meta: PaginationMeta };
    filters: {
        causer: number | null;
        subject_type: string | null;
        log: string | null;
        from: string | null;
        to: string | null;
        search: string | null;
    };
    causers: Option[];
    subjectTypes: Option[];
    logs: Option[];
}>();

const causer = ref<string>(
    props.filters.causer === null ? '' : String(props.filters.causer),
);
const subjectType = ref<string>(props.filters.subject_type ?? '');
const log = ref<string>(props.filters.log ?? '');
const from = ref<string>(props.filters.from ?? '');
const to = ref<string>(props.filters.to ?? '');

const expanded = ref<number | null>(null);

const columns: Column<AuditRow>[] = [
    { key: 'created_at', label: 'When', width: '11rem' },
    { key: 'causer', label: 'Who', width: '10rem' },
    { key: 'description', label: 'What' },
    { key: 'subject_label', label: 'Record', hideOnMobile: true },
    { key: 'log_name', label: 'Area', hideOnMobile: true, width: '8rem' },
];

function withAll(options: Option[], label: string): Option[] {
    return [{ value: '', label }, ...options];
}

function applyFilters(): void {
    router.get(
        '/app/audit',
        {
            causer: causer.value || undefined,
            subject_type: subjectType.value || undefined,
            log: log.value || undefined,
            from: from.value || undefined,
            to: to.value || undefined,
            search: props.filters.search ?? undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function toggle(row: AuditRow): void {
    expanded.value = expanded.value === row.id ? null : row.id;
}

function formatWhen(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function hasProperties(row: AuditRow): boolean {
    return Object.keys(row.properties ?? {}).length > 0;
}
</script>

<template>
    <AdminLayout
        title="Audit"
        heading="Audit trail"
        :description="`${activities.meta.total} recorded actions`"
    >
        <AppCard class="mb-4">
            <div class="flex items-start gap-3">
                <ScrollText class="mt-0.5 size-5 shrink-0 text-brand-700" />
                <p class="text-sm text-muted-foreground">
                    Every entry here is permanent. Ledgers are corrected by
                    reversing entries, never by editing, so an action that
                    happened cannot be missing from this list — and a correction
                    appears as its own row alongside what it corrected.
                </p>
            </div>
        </AppCard>

        <DataTable
            :rows="activities.data"
            :columns="columns"
            :meta="activities.meta"
            :search="filters.search ?? ''"
            searchable
            search-placeholder="Search descriptions…"
            empty-title="Nothing matches"
            empty-description="Widen the dates or clear the filters."
            @row-click="toggle"
        >
            <template #toolbar>
                <SelectInput
                    v-model="causer"
                    :options="withAll(causers, 'Anyone')"
                    class="h-9 w-44"
                    @change="applyFilters"
                />
                <SelectInput
                    v-model="subjectType"
                    :options="withAll(subjectTypes, 'Any record')"
                    class="h-9 w-44"
                    @change="applyFilters"
                />
                <SelectInput
                    v-model="log"
                    :options="withAll(logs, 'Any area')"
                    class="h-9 w-36"
                    @change="applyFilters"
                />
                <TextInput
                    v-model="from"
                    type="date"
                    class="h-9 w-40"
                    aria-label="From"
                    @change="applyFilters"
                />
                <TextInput
                    v-model="to"
                    type="date"
                    class="h-9 w-40"
                    aria-label="To"
                    @change="applyFilters"
                />
            </template>

            <template #cell-created_at="{ row }">
                <span class="tabular-nums">{{
                    formatWhen(row.created_at)
                }}</span>
            </template>

            <template #cell-causer="{ row }">
                {{ row.causer?.name ?? 'System' }}
            </template>

            <template #cell-description="{ row }">
                <div class="flex items-start gap-2">
                    <component
                        :is="expanded === row.id ? ChevronDown : ChevronRight"
                        v-if="hasProperties(row)"
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                    <div>
                        <p class="text-sm">{{ row.description }}</p>
                        <pre
                            v-if="expanded === row.id && hasProperties(row)"
                            class="mt-2 max-w-full overflow-x-auto rounded-md bg-muted p-3 text-xs text-muted-foreground"
                            >{{ JSON.stringify(row.properties, null, 2) }}</pre>
                    </div>
                </div>
            </template>

            <template #cell-log_name="{ row }">
                <StatusBadge
                    v-if="row.log_name"
                    :status="row.log_name"
                    tone="neutral"
                    size="sm"
                />
                <span v-else>—</span>
            </template>
        </DataTable>
    </AdminLayout>
</template>
