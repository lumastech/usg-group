<script setup lang="ts">
/**
 * The committee register: who is serving, who has served, and who would come next.
 *
 * The succession panel is a proposal and says so. The constitution moves each deputy
 * up, but nobody is appointed until the group confirms it office by office — so every
 * line offers "Record this" rather than happening on its own.
 */
import { useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    CalendarClock,
    Gavel,
    ShieldCheck,
    TriangleAlert,
    UserPlus,
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
    StatCard,
    StatusBadge,
    TextInput,
    TextareaInput,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type {
    CommitteeRoleOption,
    CommitteeTerm,
    GovernanceCycle,
    MemberOption,
    SuccessionProposal,
} from '@/types/governance';

const props = defineProps<{
    cycle: GovernanceCycle | null;
    current: CommitteeTerm[];
    history: CommitteeTerm[];
    succession: SuccessionProposal[];
    roles: CommitteeRoleOption[];
    members: MemberOption[];
    abilities: { record: boolean };
}>();

/**
 * A refusal from the domain — a seat already taken, notice not served — arrives keyed
 * on `term` rather than on a field, so it is read off the page's shared errors.
 */
const page = usePage();

const domainError = computed<string | null>(
    () => (page.props.errors as Record<string, string>).term ?? null,
);

const appointing = ref(false);
const ending = ref<CommitteeTerm | null>(null);
const endingOpen = ref(false);

const appointForm = useForm({
    member_id: null as number | null,
    role: 'chairperson',
    started_at: new Date().toISOString().slice(0, 10),
});

const endForm = useForm({
    end_reason: 'term_end',
    ended_at: new Date().toISOString().slice(0, 10),
    resignation_notice_date: '',
    notice_waiver_note: '',
});

const roleOptions = computed(() =>
    props.roles.map((role) => ({ value: role.value, label: role.label })),
);

const memberOptions = computed(() =>
    props.members.map((member) => ({
        value: member.id,
        label: `${member.member_number}. ${member.full_name}`,
    })),
);

const overdue = computed(() => props.current.filter((term) => term.is_overdue));

const isResignation = computed(() => endForm.end_reason === 'resigned');

/** A month after notice: the earliest the resignation may bite without a waiver. */
const earliestEnd = computed<string | null>(() => {
    if (!isResignation.value || !endForm.resignation_notice_date) {
        return null;
    }

    const notice = new Date(endForm.resignation_notice_date);
    const day = notice.getDate();
    const target = new Date(notice);

    target.setMonth(target.getMonth() + 1);

    /* Match the server: a month's notice never overflows into the next month. */
    if (target.getDate() !== day) {
        target.setDate(0);
    }

    return target.toISOString().slice(0, 10);
});

const needsWaiver = computed(
    () =>
        earliestEnd.value !== null &&
        endForm.ended_at !== '' &&
        endForm.ended_at < earliestEnd.value,
);

function openAppoint(role?: string, memberId?: number | null): void {
    appointForm.clearErrors();
    appointForm.role = role ?? 'chairperson';
    appointForm.member_id = memberId ?? null;
    appointing.value = true;
}

function submitAppoint(): void {
    appointForm.post('/app/governance/committee', {
        preserveScroll: true,
        onSuccess: () => {
            appointing.value = false;
            appointForm.reset('member_id');
        },
    });
}

function openEnd(term: CommitteeTerm): void {
    endForm.clearErrors();
    endForm.end_reason = 'term_end';
    endForm.resignation_notice_date = '';
    endForm.notice_waiver_note = '';
    ending.value = term;
    endingOpen.value = true;
}

function submitEnd(): void {
    if (ending.value === null) {
        return;
    }

    endForm.delete(`/app/governance/committee/${ending.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            endingOpen.value = false;
            ending.value = null;
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Committee"
        heading="Committee"
        description="Who holds which office, for how long, and who the constitution says comes next."
    >
        <div class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Serving"
                    :value="current.length"
                    :icon="ShieldCheck"
                    hint="Terms in progress"
                />
                <StatCard
                    label="Past terms"
                    :value="history.length"
                    :icon="CalendarClock"
                    hint="This cycle"
                />
                <StatCard
                    label="Past a year"
                    :value="overdue.length"
                    :icon="TriangleAlert"
                    :accent="overdue.length ? 'gold' : 'none'"
                    hint="Terms due to be renewed"
                />
            </div>

            <AppCard
                title="Serving now"
                description="A term grants the matching portal role for exactly as long as it runs."
            >
                <template #actions>
                    <Can permission="governance.record">
                        <AppButton size="sm" @click="openAppoint()">
                            <UserPlus class="size-4" />
                            Record a term
                        </AppButton>
                    </Can>
                </template>

                <EmptyState
                    v-if="!current.length"
                    title="No committee is serving"
                    description="Record each office as the group elects it."
                    :icon="Gavel"
                />

                <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="term in current"
                        :key="term.id"
                        class="rounded-xl border border-border bg-card p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p
                                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    {{ term.role_label }}
                                </p>
                                <p class="mt-1 font-medium text-foreground">
                                    {{ term.member_name }}
                                </p>
                            </div>
                            <StatusBadge
                                :status="term.is_overdue ? 'due' : 'active'"
                                :label="
                                    term.is_overdue ? 'Past a year' : 'Serving'
                                "
                                size="sm"
                            />
                        </div>

                        <dl
                            class="mt-3 space-y-1 text-xs text-muted-foreground"
                        >
                            <div class="flex justify-between gap-2">
                                <dt>Took office</dt>
                                <dd class="text-foreground tabular-nums">
                                    {{ term.started_at }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt>Year ends</dt>
                                <dd class="text-foreground tabular-nums">
                                    {{ term.expires_on }}
                                </dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt>Portal role</dt>
                                <dd class="text-foreground">
                                    {{
                                        term.portal_role ??
                                        'none — bank signatory only'
                                    }}
                                </dd>
                            </div>
                        </dl>

                        <AppButton
                            v-if="term.abilities.end"
                            variant="outline"
                            size="sm"
                            class="mt-3 w-full"
                            @click="openEnd(term)"
                        >
                            End this term
                        </AppButton>
                    </article>
                </div>
            </AppCard>

            <AppCard
                title="Succession proposal"
                description="What the next committee would look like if the constitution's order were followed. Nothing here is appointed — the group confirms each office."
            >
                <ul class="divide-y divide-border">
                    <li
                        v-for="line in succession"
                        :key="line.role"
                        class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-foreground">
                                {{ line.role_label }}
                            </p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                {{ line.rationale }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-3">
                            <div class="flex items-center gap-2 text-sm">
                                <span class="text-muted-foreground">
                                    {{ line.incumbent_name ?? 'vacant' }}
                                </span>
                                <ArrowUpRight
                                    class="size-4 text-muted-foreground"
                                />
                                <span
                                    :class="
                                        line.needs_nomination
                                            ? 'text-muted-foreground italic'
                                            : 'font-medium text-foreground'
                                    "
                                >
                                    {{
                                        line.proposed_name ?? 'to be nominated'
                                    }}
                                </span>
                            </div>

                            <Can permission="governance.record">
                                <AppButton
                                    variant="outline"
                                    size="sm"
                                    @click="
                                        openAppoint(
                                            line.role,
                                            line.proposed_member_id,
                                        )
                                    "
                                >
                                    Record this
                                </AppButton>
                            </Can>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AppCard
                title="Term history"
                description="Every term this cycle that has ended, most recent first."
            >
                <EmptyState
                    v-if="!history.length"
                    title="No term has ended yet"
                    description="Endings appear here with the reason the group recorded."
                    :icon="CalendarClock"
                />

                <ol
                    v-else
                    class="relative space-y-4 border-l border-border pl-5"
                >
                    <li v-for="term in history" :key="term.id" class="relative">
                        <span
                            class="absolute top-1.5 -left-[1.4rem] size-2.5 rounded-full"
                            :class="
                                term.end_reason === 'removed'
                                    ? 'bg-destructive'
                                    : 'bg-brand-500'
                            "
                        />
                        <p class="text-sm text-foreground">
                            <span class="font-medium">{{
                                term.member_name
                            }}</span>
                            as {{ term.role_label }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ term.started_at }} – {{ term.ended_at }} ·
                            {{ term.end_reason_label }}
                            <template v-if="term.resignation_notice_date">
                                · notice given
                                {{ term.resignation_notice_date }}
                            </template>
                        </p>
                        <p
                            v-if="term.notice_waiver_note"
                            class="mt-1 rounded-md bg-muted px-2 py-1 text-xs text-muted-foreground"
                        >
                            Notice waived: {{ term.notice_waiver_note }}
                        </p>
                    </li>
                </ol>
            </AppCard>
        </div>

        <Modal
            v-model:open="appointing"
            title="Record a term"
            description="The matching portal role is granted for as long as the term runs."
        >
            <form class="space-y-4" @submit.prevent="submitAppoint">
                <FormField
                    label="Member"
                    :error="appointForm.errors.member_id"
                    required
                >
                    <SelectInput
                        v-model="appointForm.member_id"
                        :options="memberOptions"
                        placeholder="Choose a member"
                        :invalid="!!appointForm.errors.member_id"
                    />
                </FormField>

                <FormField
                    label="Office"
                    :error="appointForm.errors.role"
                    hint="A signatory is recorded but granted nothing in the portal."
                    required
                >
                    <SelectInput
                        v-model="appointForm.role"
                        :options="roleOptions"
                        :invalid="!!appointForm.errors.role"
                    />
                </FormField>

                <FormField
                    label="Took office"
                    :error="appointForm.errors.started_at"
                    required
                >
                    <TextInput
                        v-model="appointForm.started_at"
                        type="date"
                        :invalid="!!appointForm.errors.started_at"
                    />
                </FormField>

                <p v-if="domainError" class="text-sm text-destructive">
                    {{ domainError }}
                </p>

                <div class="flex justify-end gap-2 pt-1">
                    <AppButton variant="ghost" @click="appointing = false"
                        >Cancel</AppButton
                    >
                    <AppButton type="submit" :loading="appointForm.processing">
                        Record term
                    </AppButton>
                </div>
            </form>
        </Modal>

        <Modal
            v-model:open="endingOpen"
            title="End a term"
            description="A removal is not recorded here — it is the consequence of a no-confidence motion carrying."
            @close="ending = null"
        >
            <form v-if="ending" class="space-y-4" @submit.prevent="submitEnd">
                <p
                    class="rounded-lg bg-muted px-3 py-2 text-sm text-muted-foreground"
                >
                    {{ ending.member_name }} as
                    <span class="text-foreground">{{ ending.role_label }}</span>
                    since {{ ending.started_at }}.
                </p>

                <FormField
                    label="Reason"
                    :error="endForm.errors.end_reason"
                    required
                >
                    <SelectInput
                        v-model="endForm.end_reason"
                        :options="[
                            { value: 'term_end', label: 'Term ended' },
                            { value: 'resigned', label: 'Resigned' },
                        ]"
                        :invalid="!!endForm.errors.end_reason"
                    />
                </FormField>

                <FormField
                    v-if="isResignation"
                    label="Notice given on"
                    :error="endForm.errors.resignation_notice_date"
                    hint="The month runs from this day, not from today."
                    required
                >
                    <TextInput
                        v-model="endForm.resignation_notice_date"
                        type="date"
                        :invalid="!!endForm.errors.resignation_notice_date"
                    />
                </FormField>

                <FormField
                    label="Term ends"
                    :error="endForm.errors.ended_at"
                    :hint="
                        earliestEnd
                            ? `A month's notice runs to ${earliestEnd}.`
                            : undefined
                    "
                    required
                >
                    <TextInput
                        v-model="endForm.ended_at"
                        type="date"
                        :invalid="!!endForm.errors.ended_at"
                    />
                </FormField>

                <FormField
                    v-if="needsWaiver"
                    label="Waiver note"
                    :error="endForm.errors.notice_waiver_note"
                    hint="Leaving before the notice runs needs a written reason the committee can stand behind."
                    required
                >
                    <TextareaInput
                        v-model="endForm.notice_waiver_note"
                        :rows="3"
                        :invalid="!!endForm.errors.notice_waiver_note"
                    />
                </FormField>

                <p v-if="domainError" class="text-sm text-destructive">
                    {{ domainError }}
                </p>

                <div class="flex justify-end gap-2 pt-1">
                    <AppButton variant="ghost" @click="endingOpen = false"
                        >Cancel</AppButton
                    >
                    <AppButton
                        type="submit"
                        variant="destructive"
                        :loading="endForm.processing"
                    >
                        End term
                    </AppButton>
                </div>
            </form>
        </Modal>
    </AdminLayout>
</template>
