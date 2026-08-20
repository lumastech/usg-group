<script setup lang="ts">
/**
 * The amendment log, and the six-month gate in front of the proposal form.
 *
 * The countdown comes from the server, so what greys out the form is the same
 * calculation that would refuse the submission. Each entry shows the wording before
 * and after side by side — the log reads as a history of the document rather than a
 * list of decisions about it.
 */
import { useForm, usePage } from '@inertiajs/vue3';
import { CalendarClock, FileText, Lock, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    Can,
    EmptyState,
    FormField,
    Modal,
    SelectInput,
    StatusBadge,
    TextInput,
    TextareaInput,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type {
    Amendment,
    AmendmentWindow,
    GovernanceCycle,
} from '@/types/governance';

const props = defineProps<{
    cycle: GovernanceCycle | null;
    amendments: Amendment[];
    window: AmendmentWindow | null;
    meetings: { id: number; label: string }[];
    abilities: { record: boolean };
}>();

/** The six-month refusal arrives keyed on `amendment`, not on a field. */
const page = usePage();

const domainError = computed<string | null>(
    () => (page.props.errors as Record<string, string>).amendment ?? null,
);

const proposing = ref(false);

const form = useForm({
    meeting_id: null as number | null,
    subject: '',
    section_reference: '',
    current_text: '',
    proposed_text: '',
    effective_date: '',
});

const isOpen = computed(() => props.window?.is_open ?? false);

const canPropose = computed(
    () => props.abilities.record && isOpen.value && props.meetings.length > 0,
);

const meetingOptions = computed(() =>
    props.meetings.map((meeting) => ({
        value: meeting.id,
        label: meeting.label,
    })),
);

function submit(): void {
    form.post('/app/governance/amendments', {
        preserveScroll: true,
        onSuccess: () => {
            proposing.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Amendments"
        heading="Amendments"
        description="Changes to the constitution, and the six months it is entitled to between them."
    >
        <div class="space-y-5">
            <!-- The gate. Shown whether or not the reader can propose, because the
                 date matters to everybody who might want to. -->
            <AppCard v-if="window" flush>
                <div class="flex items-start gap-4 p-5">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-lg"
                        :class="
                            isOpen
                                ? 'bg-brand-50 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300'
                                : 'bg-gold-50 text-gold-700 dark:bg-gold-400/10 dark:text-gold-300'
                        "
                    >
                        <component
                            :is="isOpen ? CalendarClock : Lock"
                            class="size-5"
                        />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-foreground">
                            <template v-if="isOpen">
                                The constitution may be amended
                            </template>
                            <template v-else>
                                Amendments are closed for another
                                {{ window.days_until_open }}
                                {{
                                    window.days_until_open === 1
                                        ? 'day'
                                        : 'days'
                                }}
                            </template>
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            <template v-if="window.last_amended_on">
                                Last amended on {{ window.last_amended_on
                                }}<template v-if="window.last_amended_section">
                                    ({{
                                        window.last_amended_section
                                    }})</template
                                >. Six clear months must pass between changes,
                                so the next may be proposed from
                                <span class="text-foreground">{{
                                    window.opens_on
                                }}</span
                                >.
                            </template>
                            <template v-else>
                                Nothing has been amended this cycle. The six
                                months run from the cycle's start, so proposals
                                open on
                                <span class="text-foreground">{{
                                    window.opens_on
                                }}</span
                                >.
                            </template>
                        </p>
                    </div>

                    <Can permission="governance.record">
                        <AppButton
                            size="sm"
                            :disabled="!canPropose"
                            class="shrink-0"
                            @click="proposing = true"
                        >
                            <Plus class="size-4" />
                            Propose
                        </AppButton>
                    </Can>
                </div>
            </AppCard>

            <AppCard
                title="Amendment log"
                description="Every change put to the group this cycle, carried or not."
            >
                <EmptyState
                    v-if="!amendments.length"
                    title="The constitution stands unamended"
                    description="Proposals appear here with the wording they would replace."
                    :icon="FileText"
                />

                <ul v-else class="space-y-4">
                    <li
                        v-for="amendment in amendments"
                        :key="amendment.id"
                        class="rounded-xl border border-border bg-card p-4"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-foreground">
                                    {{ amendment.section_reference }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ amendment.motion?.subject }}
                                    · effective {{ amendment.effective_date }}
                                </p>
                            </div>

                            <StatusBadge
                                v-if="amendment.motion?.is_decided"
                                :status="
                                    amendment.motion.passed
                                        ? 'approved'
                                        : 'rejected'
                                "
                                :label="
                                    amendment.motion.passed
                                        ? 'Carried'
                                        : 'Failed'
                                "
                                size="sm"
                            />
                            <StatusBadge
                                v-else
                                status="pending"
                                label="On the table"
                                size="sm"
                            />
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-lg bg-muted p-3">
                                <p
                                    class="text-[0.65rem] font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    Current wording
                                </p>
                                <p
                                    class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                                >
                                    {{ amendment.current_text }}
                                </p>
                            </div>
                            <div
                                class="rounded-lg border border-brand-200 bg-brand-50/60 p-3 dark:border-brand-400/25 dark:bg-brand-400/5"
                            >
                                <p
                                    class="text-[0.65rem] font-medium tracking-wide text-brand-700 uppercase dark:text-brand-300"
                                >
                                    Proposed wording
                                </p>
                                <p
                                    class="mt-1 text-sm whitespace-pre-line text-foreground"
                                >
                                    {{ amendment.proposed_text }}
                                </p>
                            </div>
                        </div>

                        <p
                            v-if="amendment.motion?.is_decided"
                            class="mt-3 text-xs text-muted-foreground"
                        >
                            {{ amendment.motion.votes_for }} for,
                            {{ amendment.motion.votes_against }} against,
                            {{ amendment.motion.abstentions }} abstained —
                            {{ amendment.motion.threshold_explanation }}.
                        </p>
                    </li>
                </ul>
            </AppCard>
        </div>

        <Modal
            v-model:open="proposing"
            title="Propose an amendment"
            size="lg"
            description="It goes on the table as a motion, and needs 60% of the members present at the meeting."
        >
            <form class="space-y-4" @submit.prevent="submit">
                <FormField
                    label="Meeting"
                    :error="form.errors.meeting_id"
                    required
                >
                    <SelectInput
                        v-model="form.meeting_id"
                        :options="meetingOptions"
                        placeholder="Which meeting will decide it"
                        :invalid="!!form.errors.meeting_id"
                    />
                </FormField>

                <FormField label="Motion" :error="form.errors.subject" required>
                    <TextInput
                        v-model="form.subject"
                        placeholder="What the change is, in one line"
                        :invalid="!!form.errors.subject"
                    />
                </FormField>

                <FormField
                    label="Section"
                    :error="form.errors.section_reference"
                    required
                >
                    <TextInput
                        v-model="form.section_reference"
                        placeholder="e.g. Section 4.2"
                        :invalid="!!form.errors.section_reference"
                    />
                </FormField>

                <FormField
                    label="Current wording"
                    :error="form.errors.current_text"
                    required
                >
                    <TextareaInput v-model="form.current_text" :rows="3" />
                </FormField>

                <FormField
                    label="Proposed wording"
                    :error="form.errors.proposed_text"
                    required
                >
                    <TextareaInput v-model="form.proposed_text" :rows="3" />
                </FormField>

                <FormField
                    label="Effective from"
                    :error="form.errors.effective_date"
                    required
                >
                    <TextInput
                        v-model="form.effective_date"
                        type="date"
                        :invalid="!!form.errors.effective_date"
                    />
                </FormField>

                <p v-if="domainError" class="text-sm text-destructive">
                    {{ domainError }}
                </p>

                <div class="flex justify-end gap-2 pt-1">
                    <AppButton variant="ghost" @click="proposing = false"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :loading="form.processing">
                        Put it on the table
                    </AppButton>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>
