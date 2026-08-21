<script setup lang="ts">
/**
 * One place to find every sheet the group keeps.
 *
 * The hub owns no figures. Each card links to the export the owning module already
 * renders, so a report can never drift from the screen it belongs to — and cards the
 * signed-in user has no permission for are never sent to the browser at all.
 */
import { Link, useForm } from '@inertiajs/vue3';
import { Download, FileText, Package } from '@lucide/vue';
import { computed } from 'vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    SelectInput,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { ReportCard } from '@/types/shareout';

const props = defineProps<{
    cycle: { id: number; name: string; status: string; status_label: string } | null;
    months: {
        id: number;
        sequence: number;
        label: string;
        is_current: boolean;
    }[];
    reports: ReportCard[];
    abilities: { buildPack: boolean };
}>();

const form = useForm({
    month: props.months.find((month) => month.is_current)?.sequence ?? null,
});

const monthOptions = computed(() =>
    props.months.map((month) => ({
        value: month.sequence,
        label: month.label,
    })),
);

/** A month-scoped export carries the sequence; the rest are whole-cycle. */
function href(report: ReportCard, format: string): string {
    return report.takes_month && form.month
        ? `${report.href}/${format}?month=${form.month}`
        : `${report.href}/${format}`;
}

function buildPack(): void {
    form.post('/app/reports/statement-pack', { preserveScroll: true });
}
</script>

<template>
    <AdminLayout
        title="Reports"
        heading="Reports"
        description="Every sheet the group keeps, in the format it keeps it."
    >
        <AppCard v-if="!cycle">
            <EmptyState
                title="No active cycle"
                description="Activate a cycle to download its reports."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <AppCard
                v-if="months.length"
                title="Month"
                description="Applies to the month-scoped sheets and to the statement pack."
            >
                <SelectInput
                    v-model="form.month"
                    label="Cycle month"
                    :options="monthOptions"
                    :error="form.errors.month"
                />
            </AppCard>

            <div class="grid gap-4 md:grid-cols-2">
                <AppCard
                    v-for="report in reports"
                    :key="report.key"
                    :title="report.title"
                    :description="report.description"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <AppButton
                            v-for="format in report.formats"
                            :key="format"
                            as="a"
                            :href="href(report, format)"
                            variant="secondary"
                        >
                            <component
                                :is="format === 'xlsx' ? Download : FileText"
                                class="size-4"
                            />
                            {{ format.toUpperCase() }}
                        </AppButton>

                        <Link
                            :href="report.screen"
                            class="ms-auto text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                        >
                            Open the screen
                        </Link>
                    </div>
                </AppCard>
            </div>

            <AppCard
                v-if="abilities.buildPack"
                title="Monthly statement pack"
                description="The month's savings, loans, fund and declaration sheets plus a statement for every member, rendered in one go."
            >
                <div class="space-y-3">
                    <p class="text-sm text-muted-foreground">
                        The pack is written to the private disk, ready for the
                        monthly mail-out. Rebuilding a month replaces the last
                        build of it.
                    </p>

                    <AppButton :disabled="form.processing" @click="buildPack">
                        <Package class="size-4" />
                        Build the pack
                    </AppButton>
                </div>
            </AppCard>
        </div>
    </AdminLayout>
</template>
