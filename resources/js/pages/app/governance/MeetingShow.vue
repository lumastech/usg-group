<script setup lang="ts">
/**
 * The meeting room screen, built for a phone held in one hand.
 *
 * The roll is a list of large tap targets — one tap marks somebody present, another
 * marks them away — and the ring above it counts the room as it fills. Each tap is one
 * small request with a partial reload, so the count is the server's answer rather than
 * something the browser guessed.
 *
 * The motions panel shows the bar before anybody votes, and which population that bar
 * is taken against: removing an officer is measured against the whole membership,
 * amending the constitution against the people in the room. When the meeting is short
 * of quorum, deciding is disabled and says why rather than failing on submit.
 */
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    Gavel,
    Plus,
    TriangleAlert,
    UserCheck,
} from '@lucide/vue';
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
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type {
    MeetingDetail,
    Motion,
    Quorum,
    RollEntry,
} from '@/types/governance';

const props = defineProps<{
    meeting: MeetingDetail;
    roll: RollEntry[];
    quorum: Quorum;
    motions: Motion[];
    abilities: { record: boolean };
}>();

/** Quorum refusals and the like arrive keyed on `motion`, not on a field. */
const page = usePage();

const domainError = computed<string | null>(
    () => (page.props.errors as Record<string, string>).motion ?? null,
);

const proposing = ref(false);
const deciding = ref<Motion | null>(null);
const decidingOpen = ref(false);

const motionForm = useForm({
    type: 'general',
    subject: '',
    target_member_id: null as number | null,
});

const tallyForm = useForm({
    votes_for: 0,
    votes_against: 0,
    abstentions: 0,
});

/** The ring: 0 to 1 of the way to quorum, capped so a full house does not overdraw. */
const ringProgress = computed(() =>
    props.quorum.needed === 0
        ? 0
        : Math.min(1, props.quorum.present / props.quorum.needed),
);

const CIRCUMFERENCE = 2 * Math.PI * 42;

const ringOffset = computed(() => CIRCUMFERENCE * (1 - ringProgress.value));

const memberOptions = computed(() =>
    props.roll.map((entry) => ({
        value: entry.id,
        label: `${entry.member_number}. ${entry.full_name}`,
    })),
);

const isNoConfidence = computed(() => motionForm.type === 'no_confidence');

function toggle(entry: RollEntry): void {
    if (!props.abilities.record) {
        return;
    }

    router.put(
        `/app/governance/meetings/${props.meeting.id}/attendance/${entry.id}`,
        { present: !entry.is_present },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['roll', 'quorum', 'motions'],
        },
    );
}

function submitMotion(): void {
    motionForm.post(`/app/governance/meetings/${props.meeting.id}/motions`, {
        preserveScroll: true,
        onSuccess: () => {
            proposing.value = false;
            motionForm.reset();
        },
    });
}

function openDecide(motion: Motion): void {
    tallyForm.clearErrors();
    tallyForm.reset();
    deciding.value = motion;
    decidingOpen.value = true;
}

function submitTally(): void {
    if (deciding.value === null) {
        return;
    }

    tallyForm.post(`/app/governance/motions/${deciding.value.id}/decide`, {
        preserveScroll: true,
        onSuccess: () => {
            decidingOpen.value = false;
            deciding.value = null;
        },
    });
}

/** Whether the tally being typed would carry, shown live under the boxes. */
const wouldCarry = computed(() => {
    if (deciding.value === null) {
        return false;
    }

    return tallyForm.votes_for >= deciding.value.requirement.needed;
});
</script>

<template>
    <AdminLayout
        :title="meeting.label"
        :heading="meeting.type_label"
        :description="meeting.subject ?? meeting.meeting_date"
    >
        <div class="space-y-5">
            <AppButton
                variant="ghost"
                size="sm"
                class="-ml-2"
                @click="router.get('/app/governance/meetings')"
            >
                <ArrowLeft class="size-4" />
                All meetings
            </AppButton>

            <!-- The quorum ring. Large, first on the page, readable across a room. -->
            <AppCard flush>
                <div
                    class="flex flex-col items-center gap-4 p-6 sm:flex-row sm:gap-8"
                >
                    <div class="relative size-28 shrink-0">
                        <svg viewBox="0 0 100 100" class="size-full -rotate-90">
                            <circle
                                cx="50"
                                cy="50"
                                r="42"
                                fill="none"
                                stroke-width="8"
                                class="stroke-muted"
                            />
                            <circle
                                cx="50"
                                cy="50"
                                r="42"
                                fill="none"
                                stroke-width="8"
                                stroke-linecap="round"
                                :stroke-dasharray="CIRCUMFERENCE"
                                :stroke-dashoffset="ringOffset"
                                :class="
                                    quorum.met
                                        ? 'stroke-brand-600 transition-[stroke-dashoffset] duration-500'
                                        : 'stroke-gold-400 transition-[stroke-dashoffset] duration-500'
                                "
                            />
                        </svg>
                        <div
                            class="absolute inset-0 flex flex-col items-center justify-center"
                        >
                            <span
                                class="text-xl font-semibold text-foreground tabular-nums"
                            >
                                {{ quorum.present }}/{{ quorum.active }}
                            </span>
                            <span class="text-[0.65rem] text-muted-foreground"
                                >present</span
                            >
                        </div>
                    </div>

                    <div class="min-w-0 text-center sm:text-left">
                        <p
                            class="text-lg font-semibold"
                            :class="
                                quorum.met
                                    ? 'text-brand-700 dark:text-brand-300'
                                    : 'text-foreground'
                            "
                        >
                            <template v-if="quorum.met">Quorum met ✓</template>
                            <template v-else>
                                {{ quorum.shortfall }} more
                                {{
                                    quorum.shortfall === 1
                                        ? 'member'
                                        : 'members'
                                }}
                                needed
                            </template>
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Quorum is 60% of the active membership —
                            {{ quorum.explanation }}.
                        </p>
                        <p
                            v-if="!quorum.met"
                            class="mt-2 flex items-start gap-2 text-sm text-muted-foreground"
                        >
                            <TriangleAlert
                                class="mt-0.5 size-4 shrink-0 text-gold-600 dark:text-gold-400"
                            />
                            Motions cannot be decided until the meeting is
                            quorate.
                        </p>
                    </div>
                </div>
            </AppCard>

            <AppCard
                title="Attendance register"
                :description="
                    abilities.record
                        ? 'Tap a name as each member arrives.'
                        : 'Who was in the room.'
                "
                flush
            >
                <ul class="divide-y divide-border">
                    <li v-for="entry in roll" :key="entry.id">
                        <button
                            type="button"
                            :disabled="!abilities.record"
                            class="flex w-full items-center gap-3 px-4 py-3.5 text-left transition-colors disabled:cursor-default"
                            :class="
                                entry.is_present
                                    ? 'bg-brand-50/70 dark:bg-brand-400/5'
                                    : 'hover:bg-accent'
                            "
                            @click="toggle(entry)"
                        >
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-full border text-sm font-medium transition-colors"
                                :class="
                                    entry.is_present
                                        ? 'border-brand-600 bg-brand-600 text-white'
                                        : 'border-input bg-card text-muted-foreground'
                                "
                            >
                                <Check v-if="entry.is_present" class="size-4" />
                                <template v-else>{{
                                    entry.member_number
                                }}</template>
                            </span>

                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm"
                                    :class="
                                        entry.is_present
                                            ? 'font-medium text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    {{ entry.full_name }}
                                </span>
                            </span>

                            <span
                                class="shrink-0 text-xs text-muted-foreground"
                            >
                                {{
                                    entry.is_present ? 'Present' : 'Tap to mark'
                                }}
                            </span>
                        </button>
                    </li>
                </ul>
            </AppCard>

            <AppCard
                title="Motions"
                description="The bar each motion has to clear is shown before anybody votes, along with the population it is measured against."
            >
                <template #actions>
                    <Can permission="governance.record">
                        <AppButton size="sm" @click="proposing = true">
                            <Plus class="size-4" />
                            Put a motion
                        </AppButton>
                    </Can>
                </template>

                <EmptyState
                    v-if="!motions.length"
                    title="Nothing on the table"
                    description="Motions put at this meeting appear here with their tallies."
                    :icon="Gavel"
                />

                <ul v-else class="space-y-3">
                    <li
                        v-for="motion in motions"
                        :key="motion.id"
                        class="rounded-xl border border-border bg-card p-4"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-foreground">
                                    {{ motion.subject }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ motion.type_label }}
                                    <template v-if="motion.target_name">
                                        · concerning {{ motion.target_name }}
                                    </template>
                                    <template v-if="motion.proposed_by_name">
                                        · proposed by
                                        {{ motion.proposed_by_name }}
                                    </template>
                                </p>
                            </div>

                            <StatusBadge
                                v-if="motion.is_decided"
                                :status="
                                    motion.passed ? 'approved' : 'rejected'
                                "
                                :label="motion.passed ? 'Carried' : 'Failed'"
                                size="sm"
                            />
                            <StatusBadge
                                v-else
                                status="pending"
                                label="On the table"
                                size="sm"
                            />
                        </div>

                        <!-- The arithmetic, spelled out. As recorded once decided; live until then. -->
                        <p
                            class="mt-3 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground"
                        >
                            <span class="font-medium text-foreground">
                                {{
                                    motion.threshold_explanation ??
                                    motion.requirement.explanation
                                }}
                            </span>
                            — 60% of
                            {{
                                motion.threshold_basis === 'total_members'
                                    ? 'the whole active membership, so absence counts against the motion'
                                    : 'the members present in the room'
                            }}.
                        </p>

                        <div
                            v-if="motion.is_decided"
                            class="mt-3 flex flex-wrap gap-4 text-sm tabular-nums"
                        >
                            <span class="text-foreground"
                                >{{ motion.votes_for }} for</span
                            >
                            <span class="text-muted-foreground"
                                >{{ motion.votes_against }} against</span
                            >
                            <span class="text-muted-foreground"
                                >{{ motion.abstentions }} abstained</span
                            >
                        </div>

                        <div v-else class="mt-3">
                            <AppButton
                                v-if="motion.abilities.decide"
                                size="sm"
                                variant="outline"
                                @click="openDecide(motion)"
                            >
                                <UserCheck class="size-4" />
                                Record the show of hands
                            </AppButton>
                            <p
                                v-else-if="motion.requirement.blocked_reason"
                                class="text-xs text-muted-foreground"
                            >
                                {{ motion.requirement.blocked_reason }}
                            </p>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>

        <Modal v-model:open="proposing" title="Put a motion">
            <form class="space-y-4" @submit.prevent="submitMotion">
                <FormField
                    label="Type"
                    :error="motionForm.errors.type"
                    hint="The type fixes the threshold; it is not chosen separately."
                    required
                >
                    <SelectInput
                        v-model="motionForm.type"
                        :options="[
                            {
                                value: 'general',
                                label: 'General — 60% of members present',
                            },
                            {
                                value: 'no_confidence',
                                label: 'No confidence — 60% of total members',
                            },
                        ]"
                        :invalid="!!motionForm.errors.type"
                    />
                </FormField>

                <FormField
                    label="Motion"
                    :error="motionForm.errors.subject"
                    required
                >
                    <TextInput
                        v-model="motionForm.subject"
                        placeholder="What is being put to the group"
                        :invalid="!!motionForm.errors.subject"
                    />
                </FormField>

                <FormField
                    v-if="isNoConfidence"
                    label="Officer concerned"
                    :error="motionForm.errors.target_member_id"
                    hint="If the motion carries, their terms end as Removed."
                    required
                >
                    <SelectInput
                        v-model="motionForm.target_member_id"
                        :options="memberOptions"
                        placeholder="Choose a member"
                        :invalid="!!motionForm.errors.target_member_id"
                    />
                </FormField>

                <p v-if="domainError" class="text-sm text-destructive">
                    {{ domainError }}
                </p>

                <div class="flex justify-end gap-2 pt-1">
                    <AppButton variant="ghost" @click="proposing = false"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :loading="motionForm.processing">
                        Put the motion
                    </AppButton>
                </div>
            </form>
        </Modal>

        <Modal
            v-model:open="decidingOpen"
            title="Record the show of hands"
            description="Tallies only — the constitution votes by raised hand, so no individual vote is stored."
            @close="deciding = null"
        >
            <form
                v-if="deciding"
                class="space-y-4"
                @submit.prevent="submitTally"
            >
                <p class="rounded-lg bg-muted px-3 py-2 text-sm">
                    <span class="text-foreground">{{ deciding.subject }}</span>
                    <span class="mt-1 block text-xs text-muted-foreground">
                        {{ deciding.requirement.explanation }}
                    </span>
                </p>

                <div class="grid grid-cols-3 gap-3">
                    <FormField label="For" :error="tallyForm.errors.votes_for">
                        <TextInput
                            v-model="tallyForm.votes_for"
                            type="number"
                            min="0"
                        />
                    </FormField>
                    <FormField
                        label="Against"
                        :error="tallyForm.errors.votes_against"
                    >
                        <TextInput
                            v-model="tallyForm.votes_against"
                            type="number"
                            min="0"
                        />
                    </FormField>
                    <FormField
                        label="Abstained"
                        :error="tallyForm.errors.abstentions"
                    >
                        <TextInput
                            v-model="tallyForm.abstentions"
                            type="number"
                            min="0"
                        />
                    </FormField>
                </div>

                <p
                    class="rounded-lg px-3 py-2 text-sm"
                    :class="
                        wouldCarry
                            ? 'bg-brand-50 text-brand-800 dark:bg-brand-400/10 dark:text-brand-200'
                            : 'bg-muted text-muted-foreground'
                    "
                >
                    <template v-if="wouldCarry">
                        This carries — {{ tallyForm.votes_for }} of the
                        {{ deciding.requirement.needed }} needed.
                    </template>
                    <template v-else>
                        {{
                            deciding.requirement.needed - tallyForm.votes_for
                        }}
                        more
                        {{
                            deciding.requirement.needed -
                                tallyForm.votes_for ===
                            1
                                ? 'vote'
                                : 'votes'
                        }}
                        would be needed to carry.
                    </template>
                </p>

                <p v-if="domainError" class="text-sm text-destructive">
                    {{ domainError }}
                </p>

                <div class="flex justify-end gap-2 pt-1">
                    <AppButton variant="ghost" @click="decidingOpen = false"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :loading="tallyForm.processing">
                        Record and settle
                    </AppButton>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>
