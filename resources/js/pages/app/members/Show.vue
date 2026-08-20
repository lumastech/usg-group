<script setup lang="ts">
/**
 * One member's profile.
 *
 * The timeline is the activity log itself rather than a parallel history table, so
 * what the committee reads here is the audit trail. Savings and loan tiles are wired
 * to the same props the ledger modules fill, and show a dash until they do.
 */
import { Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    HandCoins,
    KeyRound,
    Pencil,
    PiggyBank,
    Scale,
    UserCog,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import InviteLoginDialog from '@/components/members/InviteLoginDialog.vue';
import StatusChangeDialog from '@/components/members/StatusChangeDialog.vue';
import {
    AppButton,
    AppCard,
    EmptyState,
    StatCard,
    StatusBadge,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { EnumOption, Member, MemberActivity } from '@/types/members';

const props = defineProps<{
    member: Member;
    timeline: MemberActivity[];
    expulsionGrounds: EnumOption[];
    transitions: EnumOption[];
}>();

const statusDialogOpen = ref(false);
const inviting = ref<Member | null>(null);

const details = computed(() => [
    { label: 'Member number', value: String(props.member.member_number) },
    { label: 'NRC', value: props.member.nrc_number ?? '—' },
    { label: 'Phone', value: props.member.phone ?? '—' },
    { label: 'Address', value: props.member.physical_address ?? '—' },
    { label: 'Joined', value: formatDate(props.member.joined_on) },
    {
        label: 'Joined in month',
        value: String(props.member.joining_month_sequence),
    },
    { label: 'Login', value: props.member.email ?? 'Not linked' },
]);

function formatDate(value: string | null): string {
    if (!value) {
        return '—';
    }

    return new Date(value).toLocaleDateString('en-ZM', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatMoment(value: string | null): string {
    if (!value) {
        return '';
    }

    return new Date(value).toLocaleString('en-ZM', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <AdminLayout
        title="Member"
        :heading="member.full_name"
        :description="`Member number ${member.member_number}`"
    >
        <template #actions>
            <AppButton
                v-if="member.abilities.invite"
                variant="outline"
                @click="inviting = member"
            >
                <template #icon><KeyRound class="size-4" /></template>
                Invite login
            </AppButton>
            <AppButton
                v-if="member.abilities.changeStatus"
                variant="outline"
                @click="statusDialogOpen = true"
            >
                <template #icon><UserCog class="size-4" /></template>
                Change status
            </AppButton>
            <Link
                v-if="member.abilities.update"
                :href="`/app/members/${member.id}/edit`"
            >
                <AppButton>
                    <template #icon><Pencil class="size-4" /></template>
                    Edit
                </AppButton>
            </Link>
        </template>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard
                label="Savings"
                :ngwee="member.savings_ngwee ?? 0"
                :icon="PiggyBank"
                accent="brand"
                hint="Recorded this cycle"
            />
            <StatCard
                label="Loan balance"
                value="—"
                :icon="HandCoins"
                hint="Awaiting the loans module"
            />
            <StatCard
                label="Net value"
                value="—"
                :icon="Scale"
                hint="Savings plus interest, less loans"
            />
            <StatCard
                label="Joining fee"
                :ngwee="member.joining_fee_ngwee"
                :icon="CalendarDays"
                :hint="member.joining_fee_paid ? 'Received' : 'Outstanding'"
                accent="gold"
            />
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <AppCard title="Details" class="lg:col-span-2">
                <template #actions>
                    <StatusBadge
                        :status="member.status"
                        :label="member.status_label"
                    />
                </template>

                <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    <div v-for="detail in details" :key="detail.label">
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ detail.label }}
                        </dt>
                        <dd class="mt-1 text-sm text-card-foreground">
                            {{ detail.value }}
                        </dd>
                    </div>

                    <div v-if="member.is_diaspora">
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Location
                        </dt>
                        <dd class="mt-1">
                            <StatusBadge
                                status="diaspora"
                                tone="info"
                                size="sm"
                            />
                        </dd>
                    </div>
                </dl>

                <div
                    v-if="member.status !== 'active'"
                    class="mt-6 rounded-lg border border-border bg-muted/40 p-4"
                >
                    <p class="text-sm font-semibold text-foreground">
                        {{ member.status_label }}
                        <span
                            v-if="member.status_effective_on"
                            class="font-normal text-muted-foreground"
                        >
                            from {{ formatDate(member.status_effective_on) }}
                        </span>
                    </p>
                    <p
                        v-if="member.expulsion_ground_label"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Ground: {{ member.expulsion_ground_label }}
                    </p>
                    <p
                        v-if="member.date_of_death"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        Died {{ formatDate(member.date_of_death) }}
                    </p>
                    <p
                        v-if="member.status_reason"
                        class="mt-1 text-sm text-muted-foreground"
                    >
                        {{ member.status_reason }}
                    </p>
                </div>
            </AppCard>

            <AppCard title="Next of kin">
                <ul v-if="member.next_of_kin?.length" class="space-y-4">
                    <li v-for="kin in member.next_of_kin" :key="kin.id">
                        <p class="text-sm font-medium text-card-foreground">
                            {{ kin.name }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ kin.relationship_display
                            }}<span v-if="kin.phone"> · {{ kin.phone }}</span>
                        </p>
                    </li>
                </ul>
                <EmptyState
                    v-else
                    title="None recorded"
                    description="The commitment sheet named no next of kin for this member."
                />
            </AppCard>
        </div>

        <AppCard
            title="History"
            description="Every change recorded against this member"
            class="mt-4"
        >
            <ol
                v-if="timeline.length"
                class="relative space-y-5 border-l border-border pl-5"
            >
                <li v-for="entry in timeline" :key="entry.id" class="relative">
                    <span
                        class="absolute top-1.5 -left-[1.4375rem] size-2.5 rounded-full bg-brand-600"
                    />
                    <p class="text-sm text-card-foreground">
                        {{ entry.description }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        {{ formatMoment(entry.created_at) }}
                        <span v-if="entry.causer"> · {{ entry.causer }}</span>
                    </p>
                </li>
            </ol>
            <EmptyState
                v-else
                title="Nothing recorded yet"
                description="Changes will appear here."
            />
        </AppCard>

        <StatusChangeDialog
            v-if="member.abilities.changeStatus"
            v-model:open="statusDialogOpen"
            :member="member"
            :transitions="transitions"
            :expulsion-grounds="expulsionGrounds"
        />

        <InviteLoginDialog :member="inviting" @close="inviting = null" />
    </AdminLayout>
</template>
