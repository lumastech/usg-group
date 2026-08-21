<script setup lang="ts">
/**
 * Bringing the year the group kept by hand into the ledgers.
 *
 * Upload, look at what would happen, then confirm. The dry run below the upload is the
 * whole point of the screen: a workbook kept by hand for a year always has a row the
 * app cannot resolve, and the committee has to see that list — and the reconciliation
 * under it — before anything is written.
 */
import { router, useForm } from '@inertiajs/vue3';
import { FileSpreadsheet, TriangleAlert, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    StatCard,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { ImportPreview } from '@/types/shareout';

const props = defineProps<{
    cycle: { id: number; name: string; status_label: string } | null;
    upload: {
        path: string;
        original_name: string;
        uploaded_at: string;
    } | null;
    preview: ImportPreview | null;
}>();

const file = ref<File | null>(null);

const uploadForm = useForm<{ workbook: File | null }>({ workbook: null });
const importForm = useForm({});

const pending = computed(
    () => props.preview?.entries.filter((entry) => !entry.already_posted) ?? [],
);

const alreadyPosted = computed(
    () => (props.preview?.entries.length ?? 0) - pending.value.length,
);

function pick(event: Event): void {
    const input = event.target as HTMLInputElement;

    file.value = input.files?.[0] ?? null;
    uploadForm.workbook = file.value;
}

function submitUpload(): void {
    uploadForm.post('/app/import/upload', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            file.value = null;
            uploadForm.reset();
        },
    });
}

function confirmImport(): void {
    importForm.post('/app/import', { preserveScroll: true });
}

function discard(): void {
    router.delete('/app/import', { preserveScroll: true });
}
</script>

<template>
    <AdminLayout
        title="Import workbook"
        heading="Import the group workbook"
        description="Historical savings, loans, fund contributions and declarations, posted once."
    >
        <AppCard v-if="!cycle">
            <EmptyState
                title="No active cycle"
                description="Activate a cycle before importing history into it."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <AppCard
                title="Workbook"
                description="An .xlsx with the SAVINGS, LOANS, SOCIAL FUND and Declarations sheets."
            >
                <div class="space-y-3">
                    <input
                        type="file"
                        accept=".xlsx,.xls"
                        class="block w-full text-sm file:me-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-2 file:text-sm file:font-medium"
                        @change="pick"
                    />

                    <p
                        v-if="uploadForm.errors.workbook"
                        class="text-sm text-destructive"
                    >
                        {{ uploadForm.errors.workbook }}
                    </p>

                    <div class="flex flex-wrap items-center gap-2">
                        <AppButton
                            :disabled="!file || uploadForm.processing"
                            @click="submitUpload"
                        >
                            <Upload class="size-4" />
                            Upload and dry-run
                        </AppButton>

                        <AppButton
                            v-if="upload"
                            variant="secondary"
                            @click="discard"
                        >
                            Discard
                        </AppButton>

                        <span
                            v-if="upload"
                            class="text-sm text-muted-foreground"
                        >
                            <FileSpreadsheet class="me-1 inline size-4" />
                            {{ upload.original_name }}
                        </span>
                    </div>
                </div>
            </AppCard>

            <template v-if="preview">
                <AppCard v-if="preview.error">
                    <div
                        class="flex items-start gap-3 text-sm text-destructive"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                        <p>{{ preview.error }}</p>
                    </div>
                </AppCard>

                <template v-else>
                    <div class="grid gap-4 sm:grid-cols-3">
                        <StatCard
                            label="To post"
                            :value="pending.length"
                            accent="brand"
                            hint="Entries the ledgers do not hold yet"
                        />
                        <StatCard
                            label="Already present"
                            :value="alreadyPosted"
                            hint="Skipped — the import is idempotent"
                        />
                        <StatCard
                            label="Unresolved rows"
                            :value="preview.warnings.length"
                            :accent="
                                preview.warnings.length > 0 ? 'gold' : 'none'
                            "
                            hint="Rows matching no member in this cycle"
                        />
                    </div>

                    <AppCard
                        v-if="preview.warnings.length"
                        title="Rows that could not be resolved"
                        description="Nothing will be posted for these. Fix the workbook or the member register and upload again."
                    >
                        <ul class="space-y-1 text-sm text-muted-foreground">
                            <li
                                v-for="warning in preview.warnings"
                                :key="warning"
                            >
                                · {{ warning }}
                            </li>
                        </ul>
                    </AppCard>

                    <AppCard
                        title="Dry run"
                        description="Exactly what confirming would post."
                        flush
                    >
                        <div class="max-h-[50vh] overflow-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="sticky top-0 border-b border-border bg-card text-xs uppercase text-muted-foreground"
                                >
                                    <tr>
                                        <th class="px-3 py-2 text-left">
                                            Kind
                                        </th>
                                        <th class="px-3 py-2 text-left">#</th>
                                        <th class="px-3 py-2 text-left">
                                            Member
                                        </th>
                                        <th class="px-3 py-2 text-left">
                                            Month
                                        </th>
                                        <th class="px-3 py-2 text-right">
                                            Amount
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="entry in pending"
                                        :key="entry.key"
                                        class="border-b border-border/60"
                                    >
                                        <td
                                            class="px-3 py-2 text-xs text-muted-foreground"
                                        >
                                            {{ entry.kind.replace('_', ' ') }}
                                        </td>
                                        <td class="px-3 py-2">
                                            {{ entry.member_number }}
                                        </td>
                                        <td class="px-3 py-2">
                                            {{ entry.member_name }}
                                        </td>
                                        <td class="px-3 py-2">
                                            {{ entry.month_label }}
                                        </td>
                                        <td
                                            class="tabular px-3 py-2 text-right"
                                        >
                                            {{
                                                formatMoney(entry.amount_ngwee)
                                            }}
                                        </td>
                                    </tr>
                                    <tr v-if="pending.length === 0">
                                        <td
                                            class="px-3 py-6 text-center text-sm text-muted-foreground"
                                            colspan="5"
                                        >
                                            The ledgers already hold everything
                                            this workbook has.
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </AppCard>

                    <AppCard
                        title="Reconciliation"
                        description="The workbook's own totals, set against what the ledgers hold."
                        flush
                    >
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="border-b border-border text-xs uppercase text-muted-foreground"
                                >
                                    <tr>
                                        <th class="px-3 py-2 text-left">
                                            Line
                                        </th>
                                        <th class="px-3 py-2 text-right">
                                            Workbook
                                        </th>
                                        <th class="px-3 py-2 text-right">
                                            Ledgers
                                        </th>
                                        <th class="px-3 py-2 text-right">
                                            Difference
                                        </th>
                                        <th class="px-3 py-2 text-left">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="line in preview.reconciliation
                                            .lines"
                                        :key="line.label"
                                        class="border-b border-border/60"
                                    >
                                        <td class="px-3 py-2">
                                            <span class="font-medium">{{
                                                line.label
                                            }}</span>
                                            <p
                                                class="text-xs text-muted-foreground"
                                            >
                                                {{ line.note }}
                                            </p>
                                        </td>
                                        <td
                                            class="tabular px-3 py-2 text-right"
                                        >
                                            {{
                                                formatMoney(
                                                    line.workbook_ngwee,
                                                )
                                            }}
                                        </td>
                                        <td
                                            class="tabular px-3 py-2 text-right"
                                        >
                                            {{
                                                formatMoney(line.ledger_ngwee)
                                            }}
                                        </td>
                                        <td
                                            class="tabular px-3 py-2 text-right"
                                        >
                                            {{
                                                formatMoney(
                                                    line.difference_ngwee,
                                                )
                                            }}
                                        </td>
                                        <td class="px-3 py-2 text-xs">
                                            <span
                                                v-if="line.advisory"
                                                class="text-muted-foreground"
                                                >advisory</span
                                            >
                                            <span
                                                v-else-if="line.balanced"
                                                class="text-brand-600 dark:text-brand-400"
                                                >balanced</span
                                            >
                                            <span
                                                v-else
                                                class="text-gold-700 dark:text-gold-300"
                                                >discrepancy</span
                                            >
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </AppCard>

                    <AppButton
                        :disabled="
                            pending.length === 0 || importForm.processing
                        "
                        @click="confirmImport"
                    >
                        Import {{ pending.length }} entr(ies)
                    </AppButton>
                </template>
            </template>
        </div>
    </AdminLayout>
</template>
