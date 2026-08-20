<script setup lang="ts">
/**
 * The meetings log. Each row carries its own quorum count, so the committee can see
 * at a glance which gatherings were quorate and could therefore decide anything.
 */
import { router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';

import { ref } from 'vue';
import {
    AppButton,
    AppCard,
    Can,
    DataTable,
    FormField,
    Modal,
    SelectInput,
    StatusBadge,
    TextInput,
    TextareaInput,
} from '@/components/unity';
import type { Column } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { GovernanceCycle, MeetingRow } from '@/types/governance';

defineProps<{
    cycle: GovernanceCycle | null;
    meetings: MeetingRow[];
    abilities: { record: boolean };
}>();

const creating = ref(false);

const form = useForm({
    meeting_date: new Date().toISOString().slice(0, 10),
    type: 'monthly',
    subject: '',
    notes: '',
});

const columns: Column<MeetingRow>[] = [
    { key: 'meeting_date', label: 'Date' },
    { key: 'type_label', label: 'Type' },
    { key: 'subject', label: 'Subject', hideOnMobile: true },
    { key: 'quorum', label: 'Attendance', align: 'right' },
    { key: 'motions_count', label: 'Motions', numeric: true },
];

function open(row: MeetingRow): void {
    router.get(`/app/governance/meetings/${row.id}`);
}

function submit(): void {
    form.post('/app/governance/meetings', {
        onSuccess: () => {
            creating.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Meetings"
        heading="Meetings"
        description="Every gathering of the group, the register taken at it and what it decided."
    >
        <AppCard
            title="This cycle's meetings"
            description="Open a meeting to take the register and put motions."
            flush
        >
            <template #actions>
                <Can permission="governance.record">
                    <AppButton size="sm" @click="creating = true">
                        <Plus class="size-4" />
                        New meeting
                    </AppButton>
                </Can>
            </template>

            <DataTable
                :rows="meetings"
                :columns="columns"
                row-key="id"
                empty-title="No meetings recorded"
                empty-description="Open the register when the group next gathers."
                @row-click="open"
            >
                <template #cell-subject="{ row }">
                    <span class="text-muted-foreground">{{
                        row.subject ?? '—'
                    }}</span>
                </template>

                <template #cell-quorum="{ row }">
                    <span class="inline-flex items-center gap-2">
                        <span class="text-muted-foreground tabular-nums">
                            {{ row.quorum.present }}/{{ row.quorum.active }}
                        </span>
                        <StatusBadge
                            :status="row.quorum.met ? 'approved' : 'pending'"
                            :label="row.quorum.met ? 'Quorate' : 'No quorum'"
                            size="sm"
                        />
                    </span>
                </template>
            </DataTable>
        </AppCard>

        <Modal
            v-model:open="creating"
            title="Open a meeting register"
            description="The register can be taken on a phone in the room once the meeting exists."
        >
            <form class="space-y-4" @submit.prevent="submit">
                <FormField
                    label="Date"
                    :error="form.errors.meeting_date"
                    required
                >
                    <TextInput
                        v-model="form.meeting_date"
                        type="date"
                        :invalid="!!form.errors.meeting_date"
                    />
                </FormField>

                <FormField label="Type" :error="form.errors.type" required>
                    <SelectInput
                        v-model="form.type"
                        :options="[
                            { value: 'monthly', label: 'Monthly meeting' },
                            { value: 'special', label: 'Special meeting' },
                            { value: 'share_out', label: 'Share-out meeting' },
                        ]"
                        :invalid="!!form.errors.type"
                    />
                </FormField>

                <FormField label="Subject" :error="form.errors.subject">
                    <TextInput
                        v-model="form.subject"
                        placeholder="What the meeting is chiefly about"
                        :invalid="!!form.errors.subject"
                    />
                </FormField>

                <FormField label="Notes" :error="form.errors.notes">
                    <TextareaInput v-model="form.notes" :rows="3" />
                </FormField>

                <div class="flex justify-end gap-2 pt-1">
                    <AppButton variant="ghost" @click="creating = false"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :loading="form.processing">
                        Open register
                    </AppButton>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>
