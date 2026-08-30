<script setup lang="ts">
/**
 * The cycle's calendar, as the committee may change it.
 *
 * Every window in the portal — the member's declaration form, the trading console, the
 * notification schedule — is read from these rows, so this is where a declaration
 * period is reopened or a trading day moved. It changes dates and nothing else: the
 * savings increments, the lockdown and the eligibility checks all still run, they
 * simply run on the days set here.
 */
import { useForm } from '@inertiajs/vue3';
import { CalendarClock, RotateCcw, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    ConfirmDialog,
    EmptyState,
    FormField,
    Modal,
    StatusBadge,
    TextInput,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type {
    CalendarConstitution,
    CalendarCycle,
    CalendarMonth,
    CycleMonthSchedule,
} from '@/types/settings';

const props = defineProps<{
    cycle: CalendarCycle | null;
    months: CalendarMonth[];
    constitution: CalendarConstitution;
}>();

/** What DeclarationWindow's state means in a word, for the column that shows today. */
const WINDOW_LABELS: Record<string, string> = {
    before_declarations: 'Not yet open',
    declarations: 'Declaring',
    between: 'Between',
    trading: 'Trading',
    closed: 'Closed',
};

const editing = ref<CalendarMonth | null>(null);
const editOpen = ref(false);
const resetting = ref<CalendarMonth | null>(null);
const resetOpen = ref(false);

const form = useForm<CycleMonthSchedule>({
    declarations_open_at: '',
    declarations_close_at: '',
    trading_starts_on: '',
    trading_concludes_on: '',
    disbursement_on: '',
});

const resetForm = useForm({});

const constitutionSummary = computed(() => {
    const c = props.constitution;

    return `Declarations from ${ordinal(c.declarations_open_day)} at ${String(
        c.declarations_open_hour,
    ).padStart(2, '0')}:00 to the end of the ${ordinal(
        c.declarations_close_day,
    )}, trading from the ${ordinal(c.trading_start_day)} to the ${ordinal(
        c.disbursement_day,
    )}.`;
});

function ordinal(day: number): string {
    const suffix =
        day % 10 === 1 && day !== 11
            ? 'st'
            : day % 10 === 2 && day !== 12
              ? 'nd'
              : day % 10 === 3 && day !== 13
                ? 'rd'
                : 'th';

    return `${day}${suffix}`;
}

function formatDate(value: string): string {
    return new Date(value).toLocaleDateString('en-ZM', {
        day: 'numeric',
        month: 'short',
    });
}

function formatDateTime(value: string): string {
    return new Date(value).toLocaleString('en-ZM', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function edit(month: CalendarMonth): void {
    editing.value = month;
    editOpen.value = true;
    form.clearErrors();
    form.defaults({
        declarations_open_at: month.declarations_open_at,
        declarations_close_at: month.declarations_close_at,
        trading_starts_on: month.trading_starts_on,
        trading_concludes_on: month.trading_concludes_on,
        disbursement_on: month.disbursement_on,
    });
    form.reset();
}

/**
 * The common case this screen exists for: the window shut before everybody had
 * declared, and the committee wants the whole group back in rather than the treasurer
 * capturing them one at a time.
 */
function reopenThroughToday(): void {
    const now = new Date();
    const pad = (value: number): string => String(value).padStart(2, '0');
    const day = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;

    if (new Date(form.declarations_open_at) > now) {
        form.declarations_open_at = `${day}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
    }

    form.declarations_close_at = `${day}T23:59`;
}

function submit(): void {
    if (!editing.value) {
        return;
    }

    form.put(`/app/settings/calendar/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
}

function askReset(month: CalendarMonth): void {
    resetting.value = month;
    resetOpen.value = true;
}

function confirmReset(): void {
    if (!resetting.value) {
        return;
    }

    resetForm.post(`/app/settings/calendar/${resetting.value.id}/reset`, {
        preserveScroll: true,
        onFinish: () => {
            resetOpen.value = false;
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Cycle calendar"
        heading="Cycle calendar"
        :description="
            cycle
                ? `${cycle.name} — ${constitutionSummary}`
                : 'No cycle is running.'
        "
    >
        <div class="space-y-5">
            <AppCard v-if="cycle">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <TriangleAlert
                        class="size-5 shrink-0 text-gold-600"
                        aria-hidden="true"
                    />
                    <div class="space-y-1 text-sm">
                        <p class="font-medium text-foreground">
                            These dates are the group's windows, not its rules.
                        </p>
                        <p class="text-muted-foreground">
                            Moving them changes when the portal accepts
                            declarations and when the trading table opens. The
                            savings increments, the lockdown cap and the loan
                            eligibility checks are unaffected — they simply run
                            on the days set here. Every change is written to the
                            audit log. A month's dates must stay inside that
                            month, declarations must close before trading opens,
                            and a month that has been traded and closed can no
                            longer be moved.
                        </p>
                        <p class="text-muted-foreground">
                            Weekend policy for the 7th:
                            <span class="text-foreground">{{
                                cycle.weekend_policy_label
                            }}</span
                            >.
                        </p>
                    </div>
                </div>
            </AppCard>

            <AppCard title="Months" flush>
                <EmptyState
                    v-if="months.length === 0"
                    :icon="CalendarClock"
                    title="No months to show"
                    description="The cycle has no calendar yet."
                    class="m-5"
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr
                                class="border-b border-border text-left text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                <th class="px-5 py-3 font-medium">Month</th>
                                <th class="px-5 py-3 font-medium">
                                    Declarations
                                </th>
                                <th class="px-5 py-3 font-medium">Trading</th>
                                <th class="px-5 py-3 font-medium">
                                    Disbursement
                                </th>
                                <th class="px-5 py-3 font-medium">State</th>
                                <th class="px-5 py-3 text-right font-medium">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="month in months"
                                :key="month.id"
                                :class="month.is_current ? 'bg-muted/40' : ''"
                            >
                                <td class="px-5 py-3">
                                    <p class="font-medium text-foreground">
                                        {{ month.label }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Month {{ month.sequence }}
                                        <span v-if="month.is_current"
                                            >· this month</span
                                        >
                                    </p>
                                </td>
                                <td
                                    class="px-5 py-3 whitespace-nowrap text-muted-foreground tabular-nums"
                                >
                                    {{
                                        formatDateTime(
                                            month.declarations_open_at,
                                        )
                                    }}
                                    —
                                    {{
                                        formatDateTime(
                                            month.declarations_close_at,
                                        )
                                    }}
                                </td>
                                <td
                                    class="px-5 py-3 whitespace-nowrap text-muted-foreground tabular-nums"
                                >
                                    {{ formatDate(month.trading_starts_on) }} —
                                    {{ formatDate(month.trading_concludes_on) }}
                                </td>
                                <td
                                    class="px-5 py-3 whitespace-nowrap text-muted-foreground tabular-nums"
                                >
                                    {{ formatDate(month.disbursement_on) }}
                                </td>
                                <td class="px-5 py-3">
                                    <StatusBadge
                                        :status="month.status"
                                        size="sm"
                                    />
                                    <span
                                        v-if="month.is_current"
                                        class="ml-2 text-xs text-muted-foreground"
                                    >
                                        {{
                                            WINDOW_LABELS[month.window] ??
                                            month.window
                                        }}
                                    </span>
                                </td>
                                <td class="px-5 py-3">
                                    <div
                                        class="flex items-center justify-end gap-2"
                                    >
                                        <AppButton
                                            size="sm"
                                            variant="secondary"
                                            :disabled="!month.editable"
                                            @click="edit(month)"
                                        >
                                            Edit dates
                                        </AppButton>
                                        <AppButton
                                            size="sm"
                                            variant="ghost"
                                            :disabled="!month.editable"
                                            :aria-label="`Reset ${month.label} to the constitution's dates`"
                                            @click="askReset(month)"
                                        >
                                            <RotateCcw class="size-4" />
                                        </AppButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </AppCard>
        </div>

        <Modal
            v-model:open="editOpen"
            :title="editing ? `${editing.label} dates` : 'Dates'"
            size="lg"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Declarations open"
                        :error="form.errors.declarations_open_at"
                        required
                    >
                        <TextInput
                            v-model="form.declarations_open_at"
                            type="datetime-local"
                        />
                    </FormField>

                    <FormField
                        label="Declarations close"
                        :error="form.errors.declarations_close_at"
                        hint="The last moment a member may declare for themselves."
                        required
                    >
                        <TextInput
                            v-model="form.declarations_close_at"
                            type="datetime-local"
                        />
                    </FormField>

                    <FormField
                        label="Trading opens"
                        :error="form.errors.trading_starts_on"
                        required
                    >
                        <TextInput
                            v-model="form.trading_starts_on"
                            type="date"
                        />
                    </FormField>

                    <FormField
                        label="Trading concludes"
                        :error="form.errors.trading_concludes_on"
                        required
                    >
                        <TextInput
                            v-model="form.trading_concludes_on"
                            type="date"
                        />
                    </FormField>

                    <FormField
                        label="Disbursement"
                        :error="form.errors.disbursement_on"
                        hint="The day loans are paid out; one of the trading days."
                        required
                    >
                        <TextInput v-model="form.disbursement_on" type="date" />
                    </FormField>
                </div>

                <div
                    class="rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground"
                >
                    Reopening the declaration period lets members who have not
                    declared submit again. A declaration the committee has
                    already approved stays frozen until it is reopened on the
                    declarations screen, and a month that has already been
                    locked by its trading session keeps the figures it was built
                    from.
                    <button
                        type="button"
                        class="mt-2 block font-medium text-foreground underline underline-offset-2"
                        @click="reopenThroughToday"
                    >
                        Reopen declarations through the end of today
                    </button>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <AppButton
                        type="button"
                        variant="ghost"
                        @click="editOpen = false"
                    >
                        Cancel
                    </AppButton>
                    <AppButton type="submit" :loading="form.processing">
                        Save dates
                    </AppButton>
                </div>
            </form>
        </Modal>

        <ConfirmDialog
            v-model:open="resetOpen"
            title="Back to the constitution's dates"
            :message="
                resetting
                    ? `${resetting.label} goes back to ${constitutionSummary}`
                    : ''
            "
            confirm-label="Restore"
            :processing="resetForm.processing"
            @confirm="confirmReset"
        />
    </AdminLayout>
</template>
