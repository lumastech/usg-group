<script setup lang="ts">
/**
 * The trading console: the operational heart of the month.
 *
 * The treasurer works this sheet top-down on the day — mark what each member handed
 * over and when, pay out the queue in its order, watch the cash position at the foot.
 * Nothing here has touched a ledger yet. Concluding is the single act that posts the
 * month, and it is confirmed against a preview of exactly what will be posted.
 */
import { router, useForm } from '@inertiajs/vue3';
import {
    Banknote,
    CheckCircle2,
    Clock,
    Lock,
    Undo2,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    EmptyState,
    FormField,
    Modal,
    MoneyInput,
    SelectInput,
    StatCard,
    StatusBadge,
    TextInput,
    WindowCountdown,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { DeclarationMonth } from '@/types/declarations';
import type {
    TradingEntry,
    TradingPreview,
    TradingSession,
    TradingTotals,
} from '@/types/trading';

const props = defineProps<{
    cycle: { id: number; name: string } | null;
    month: DeclarationMonth | null;
    months: { id: number; sequence: number; label: string }[];
    session: TradingSession | null;
    entries: { data: TradingEntry[] } | TradingEntry[];
    totals: TradingTotals | null;
    preview: TradingPreview | null;
    missing: { id: number; member_number: number; full_name: string }[];
    filters?: { month: number | null };
    abilities: { operate: boolean; conclude: boolean };
}>();

/** Resources arrive unwrapped as a bare array for a plain collection. */
const rows = computed<TradingEntry[]>(() =>
    Array.isArray(props.entries) ? props.entries : props.entries.data,
);

const receiving = ref<TradingEntry | null>(null);
const disbursing = ref<TradingEntry | null>(null);
const concludeOpen = ref(false);

const receiptForm = useForm({
    actual_in_ngwee: null as number | null,
    received_at: '',
});

const disburseForm = useForm({ out_of_order_reason: '' });
const concludeForm = useForm({});

const isOpen = computed<boolean>(() => props.session?.status === 'open');
const canMark = computed<boolean>(
    () => props.abilities.operate && isOpen.value,
);

/** The FIFO queue: whoever is owed money, in the order the queue put them in. */
const owed = computed<TradingEntry[]>(() =>
    rows.value.filter((row) => row.expected_out_ngwee > 0),
);

const outstanding = computed<TradingEntry[]>(() =>
    rows.value.filter((row) => !row.is_received && row.expected_in_ngwee > 0),
);

/** The datetime-local control wants "YYYY-MM-DDTHH:mm" with no zone. */
function defaultReceivedAt(): string {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}T${pad(now.getHours())}:${pad(now.getMinutes())}`;
}

function stepMonth(sequence: number | string | null): void {
    router.get(
        '/app/trading',
        { month: sequence || undefined },
        { preserveState: false, preserveScroll: true, replace: true },
    );
}

function openReceipt(entry: TradingEntry): void {
    receiving.value = entry;
    receiptForm.clearErrors();
    receiptForm.actual_in_ngwee = entry.is_received
        ? entry.actual_in_ngwee
        : entry.expected_in_ngwee;
    receiptForm.received_at = entry.received_at
        ? entry.received_at.slice(0, 16)
        : defaultReceivedAt();
}

function submitReceipt(): void {
    if (!receiving.value) {
        return;
    }

    receiptForm.post(`/app/trading/entries/${receiving.value.id}/receipt`, {
        preserveScroll: true,
        onSuccess: () => {
            receiving.value = null;
            receiptForm.reset();
        },
    });
}

function clearReceipt(entry: TradingEntry): void {
    router.delete(`/app/trading/entries/${entry.id}/receipt`, {
        preserveScroll: true,
    });
}

function openDisbursement(entry: TradingEntry): void {
    disbursing.value = entry;
    disburseForm.clearErrors();
    disburseForm.out_of_order_reason = '';
}

function submitDisbursement(): void {
    if (!disbursing.value) {
        return;
    }

    disburseForm.post(`/app/trading/entries/${disbursing.value.id}/disburse`, {
        preserveScroll: true,
        onSuccess: () => {
            disbursing.value = null;
            disburseForm.reset();
        },
    });
}

function conclude(): void {
    if (!props.session) {
        return;
    }

    concludeForm.post(`/app/trading/sessions/${props.session.id}/conclude`, {
        preserveScroll: true,
        onSuccess: () => {
            concludeOpen.value = false;
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Trading"
        heading="Trading console"
        :description="
            month
                ? `${month.label} — concludes ${month.trading_concludes_on}`
                : cycle?.name
        "
    >
        <template #actions>
            <AppButton
                v-if="abilities.conclude && isOpen"
                size="sm"
                @click="concludeOpen = true"
            >
                <template #icon><Lock class="size-4" /></template>
                Conclude the session
            </AppButton>
        </template>

        <AppCard v-if="!month">
            <EmptyState
                title="No active cycle"
                description="Seed or activate a cycle before running a trading day."
            />
        </AppCard>

        <div v-else class="space-y-5 pb-24">
            <WindowCountdown
                :window="month.window"
                :seconds-remaining="month.seconds_remaining"
            />

            <div class="flex flex-wrap items-end gap-3">
                <FormField label="Month" class="w-56">
                    <SelectInput
                        :model-value="month.sequence"
                        :options="
                            months.map((row) => ({
                                value: row.sequence,
                                label: row.label,
                            }))
                        "
                        @update:model-value="stepMonth"
                    />
                </FormField>

                <StatusBadge
                    v-if="session"
                    :status="session.status"
                    :label="session.status_label"
                />
            </div>

            <!-- No session means the declaration window is still open: opening early
                 would lock declarations members are entitled to change. -->
            <AppCard v-if="!session">
                <EmptyState
                    title="The trading session has not opened"
                    :description="
                        month.declarations_open
                            ? `Declarations for ${month.label} close at the end of ${month.declarations_close_at.slice(0, 10)}. The session opens once they do.`
                            : 'The session opens the first time a treasurer visits this screen after declarations close.'
                    "
                />
            </AppCard>

            <template v-else>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        v-if="totals"
                        label="Received"
                        :ngwee="totals.actual_in_ngwee"
                        :icon="Wallet"
                        accent="brand"
                        :hint="`of ${formatMoney(totals.expected_in_ngwee)} expected`"
                    />
                    <StatCard
                        v-if="totals"
                        label="Paid out"
                        :ngwee="totals.actual_out_ngwee"
                        :icon="Banknote"
                        :hint="`of ${formatMoney(totals.expected_out_ngwee)} queued`"
                    />
                    <StatCard
                        v-if="totals"
                        label="Cash position"
                        :ngwee="totals.cash_position_ngwee"
                        accent="gold"
                        hint="On the table right now"
                    />
                    <StatCard
                        v-if="totals"
                        label="Still to come"
                        :value="totals.outstanding_count"
                        :icon="Clock"
                        :hint="`${totals.received_count} of ${totals.entry_count} marked`"
                    />
                </div>

                <div class="grid gap-5 xl:grid-cols-[2fr_1fr]">
                    <AppCard
                        :title="`${month.label} sheet`"
                        description="Declared beside received. Penalty days are computed from when the money actually arrived."
                        flush
                    >
                        <div v-if="rows.length === 0" class="p-5">
                            <EmptyState
                                title="Nobody on the sheet"
                                description="Entries appear here from the month's declarations and the disbursement queue."
                            />
                        </div>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead
                                    class="border-b border-border text-xs text-muted-foreground"
                                >
                                    <tr>
                                        <th
                                            class="px-5 py-2 text-left font-medium"
                                        >
                                            Member
                                        </th>
                                        <th
                                            class="px-3 py-2 text-right font-medium"
                                        >
                                            Declared
                                        </th>
                                        <th
                                            class="px-3 py-2 text-right font-medium"
                                        >
                                            Received
                                        </th>
                                        <th
                                            class="px-3 py-2 text-right font-medium"
                                        >
                                            Variance
                                        </th>
                                        <th
                                            class="px-3 py-2 text-left font-medium"
                                        >
                                            When
                                        </th>
                                        <th
                                            class="px-5 py-2 text-right font-medium"
                                        >
                                            <span class="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-border">
                                    <tr
                                        v-for="entry in rows"
                                        :key="entry.id"
                                        :class="
                                            entry.is_received
                                                ? ''
                                                : 'bg-muted/40'
                                        "
                                    >
                                        <td class="px-5 py-3">
                                            <span class="block font-medium">{{
                                                entry.member_name
                                            }}</span>
                                            <span
                                                class="block text-xs text-muted-foreground"
                                                >#{{
                                                    entry.member_number
                                                }}</span
                                            >
                                        </td>

                                        <td
                                            class="tabular px-3 py-3 text-right"
                                        >
                                            {{
                                                formatMoney(
                                                    entry.expected_in_ngwee,
                                                )
                                            }}
                                        </td>

                                        <td
                                            class="tabular px-3 py-3 text-right font-semibold"
                                        >
                                            <span v-if="entry.is_received">{{
                                                formatMoney(
                                                    entry.actual_in_ngwee,
                                                )
                                            }}</span>
                                            <span
                                                v-else
                                                class="font-normal text-muted-foreground"
                                                >—</span
                                            >
                                        </td>

                                        <td
                                            class="tabular px-3 py-3 text-right"
                                            :class="
                                                entry.is_received &&
                                                entry.variance_ngwee < 0
                                                    ? 'text-destructive'
                                                    : 'text-muted-foreground'
                                            "
                                        >
                                            {{
                                                entry.is_received
                                                    ? formatMoney(
                                                          entry.variance_ngwee,
                                                      )
                                                    : '—'
                                            }}
                                        </td>

                                        <td class="px-3 py-3">
                                            <span
                                                v-if="entry.received_at"
                                                class="flex flex-wrap items-center gap-1.5"
                                            >
                                                <span
                                                    class="text-xs text-muted-foreground"
                                                    >{{
                                                        entry.received_at
                                                            .slice(0, 16)
                                                            .replace('T', ' ')
                                                    }}</span
                                                >
                                                <!-- The chip is the treasurer's
                                                     warning that concluding will
                                                     charge this member. -->
                                                <StatusBadge
                                                    v-if="
                                                        entry.penalty_days > 0
                                                    "
                                                    status="late"
                                                    :label="`${entry.penalty_days}d late`"
                                                    size="sm"
                                                />
                                            </span>
                                            <span
                                                v-else
                                                class="text-xs text-muted-foreground"
                                                >Not yet</span
                                            >
                                        </td>

                                        <td class="px-5 py-3 text-right">
                                            <span
                                                class="flex justify-end gap-1.5"
                                            >
                                                <AppButton
                                                    v-if="canMark"
                                                    size="sm"
                                                    :variant="
                                                        entry.is_received
                                                            ? 'outline'
                                                            : 'primary'
                                                    "
                                                    @click="openReceipt(entry)"
                                                >
                                                    {{
                                                        entry.is_received
                                                            ? 'Edit'
                                                            : 'Mark received'
                                                    }}
                                                </AppButton>
                                                <AppButton
                                                    v-if="
                                                        canMark &&
                                                        entry.is_received
                                                    "
                                                    size="sm"
                                                    variant="ghost"
                                                    aria-label="Clear receipt"
                                                    @click="clearReceipt(entry)"
                                                >
                                                    <template #icon
                                                        ><Undo2 class="size-4"
                                                    /></template>
                                                </AppButton>
                                                <CheckCircle2
                                                    v-if="
                                                        !canMark &&
                                                        entry.is_received
                                                    "
                                                    class="size-5 text-brand-600"
                                                />
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </AppCard>

                    <div class="space-y-5">
                        <AppCard
                            title="Disbursements"
                            description="First come, first served, by the moment each request was captured."
                            flush
                        >
                            <div v-if="owed.length === 0" class="p-5">
                                <EmptyState
                                    title="Nothing to pay out"
                                    description="Approved loans waiting on the day appear here in queue order."
                                />
                            </div>

                            <ol v-else class="divide-y divide-border">
                                <li
                                    v-for="(entry, index) in owed"
                                    :key="entry.id"
                                    class="flex items-center gap-3 px-5 py-3"
                                >
                                    <span
                                        class="tabular grid size-8 shrink-0 place-items-center rounded-full bg-brand-50 text-xs font-semibold text-brand-700 dark:bg-brand-400/15 dark:text-brand-200"
                                    >
                                        {{ index + 1 }}
                                    </span>

                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-sm font-medium"
                                            >{{ entry.member_name }}</span
                                        >
                                        <span
                                            class="tabular block text-xs text-muted-foreground"
                                            >{{
                                                formatMoney(
                                                    entry.expected_out_ngwee,
                                                )
                                            }}</span
                                        >
                                    </span>

                                    <StatusBadge
                                        v-if="entry.is_disbursed"
                                        status="disbursed"
                                        label="Paid"
                                        size="sm"
                                    />
                                    <AppButton
                                        v-else-if="canMark"
                                        size="sm"
                                        :variant="
                                            index === 0 ? 'primary' : 'outline'
                                        "
                                        @click="openDisbursement(entry)"
                                    >
                                        {{
                                            index === 0
                                                ? 'Disburse'
                                                : 'Disburse early'
                                        }}
                                    </AppButton>
                                </li>
                            </ol>
                        </AppCard>

                        <AppCard
                            v-if="outstanding.length > 0"
                            title="Still to arrive"
                            :description="`${outstanding.length} member${outstanding.length === 1 ? '' : 's'} declared but have not paid.`"
                            flush
                        >
                            <ul class="divide-y divide-border">
                                <li
                                    v-for="entry in outstanding"
                                    :key="entry.id"
                                    class="flex items-center justify-between gap-3 px-5 py-2.5"
                                >
                                    <span class="truncate text-sm">{{
                                        entry.member_name
                                    }}</span>
                                    <span
                                        class="tabular shrink-0 text-sm text-muted-foreground"
                                        >{{
                                            formatMoney(entry.expected_in_ngwee)
                                        }}</span
                                    >
                                </li>
                            </ul>
                        </AppCard>

                        <AppCard
                            v-if="missing.length > 0"
                            title="Never declared"
                            :description="`${missing.length} active member${missing.length === 1 ? '' : 's'} are not on the sheet at all.`"
                            flush
                        >
                            <ul class="divide-y divide-border">
                                <li
                                    v-for="member in missing"
                                    :key="member.id"
                                    class="px-5 py-2.5 text-sm"
                                >
                                    {{ member.full_name }}
                                    <span class="text-xs text-muted-foreground"
                                        >#{{ member.member_number }}</span
                                    >
                                </li>
                            </ul>
                        </AppCard>
                    </div>
                </div>
            </template>
        </div>

        <!-- The day's position follows the treasurer down the sheet. -->
        <div
            v-if="totals && session"
            class="fixed inset-x-0 bottom-0 z-10 border-t border-border bg-card/95 backdrop-blur"
        >
            <div
                class="mx-auto flex max-w-full flex-wrap items-center justify-end gap-x-8 gap-y-2 px-6 py-3 text-sm"
            >
                <span class="text-muted-foreground"
                    >Expected in
                    <span class="tabular font-semibold text-foreground">{{
                        formatMoney(totals.expected_in_ngwee)
                    }}</span></span
                >
                <span class="text-muted-foreground"
                    >Received
                    <span class="tabular font-semibold text-foreground">{{
                        formatMoney(totals.actual_in_ngwee)
                    }}</span></span
                >
                <span class="text-muted-foreground"
                    >Paid out
                    <span class="tabular font-semibold text-foreground">{{
                        formatMoney(totals.actual_out_ngwee)
                    }}</span></span
                >
                <span class="text-muted-foreground"
                    >Cash position
                    <span
                        class="tabular text-base font-semibold"
                        :class="
                            totals.cash_position_ngwee < 0
                                ? 'text-destructive'
                                : 'text-foreground'
                        "
                        >{{ formatMoney(totals.cash_position_ngwee) }}</span
                    ></span
                >

                <AppButton
                    v-if="abilities.conclude && isOpen"
                    size="sm"
                    @click="concludeOpen = true"
                >
                    Conclude
                </AppButton>
            </div>
        </div>

        <ClientOnly>
            <Modal
                :open="receiving !== null"
                title="Mark money received"
                :description="`${receiving?.member_name} declared ${formatMoney(receiving?.expected_in_ngwee ?? 0)}.`"
                @close="receiving = null"
            >
                <div class="space-y-4">
                    <FormField
                        label="Amount received"
                        :error="receiptForm.errors.actual_in_ngwee"
                        required
                    >
                        <MoneyInput
                            v-model="receiptForm.actual_in_ngwee"
                            :invalid="!!receiptForm.errors.actual_in_ngwee"
                        />
                    </FormField>

                    <!-- Not defaulted silently to now: rows are often entered after
                         the fact, and the penalty hangs off when it actually came. -->
                    <FormField
                        label="When it arrived"
                        :error="receiptForm.errors.received_at"
                        hint="Anything after the trading date is charged K100 a day."
                        required
                    >
                        <TextInput
                            v-model="receiptForm.received_at"
                            type="datetime-local"
                            :invalid="!!receiptForm.errors.received_at"
                        />
                    </FormField>

                    <p
                        v-if="receiving?.declared"
                        class="text-xs text-muted-foreground"
                    >
                        Declared
                        {{
                            formatMoney(receiving.declared.saving_amount_ngwee)
                        }}
                        savings and
                        {{
                            formatMoney(
                                receiving.declared.loan_repayment_amount_ngwee,
                            )
                        }}
                        repayment. A short payment covers the savings first.
                    </p>
                </div>

                <template #footer>
                    <AppButton variant="ghost" @click="receiving = null">
                        Cancel
                    </AppButton>
                    <AppButton
                        :loading="receiptForm.processing"
                        @click="submitReceipt"
                    >
                        Mark received
                    </AppButton>
                </template>
            </Modal>

            <Modal
                :open="disbursing !== null"
                title="Pay out this loan"
                :description="`${disbursing?.member_name} is queued for ${formatMoney(disbursing?.expected_out_ngwee ?? 0)}.`"
                @close="disbursing = null"
            >
                <FormField
                    label="Reason, if paying out of turn"
                    :error="disburseForm.errors.out_of_order_reason"
                    hint="Leave blank when this member is at the head of the queue."
                >
                    <TextInput
                        v-model="disburseForm.out_of_order_reason"
                        placeholder="Medical emergency; agreed by the committee on the day."
                    />
                </FormField>

                <template #footer>
                    <AppButton variant="ghost" @click="disbursing = null">
                        Cancel
                    </AppButton>
                    <AppButton
                        :loading="disburseForm.processing"
                        @click="submitDisbursement"
                    >
                        Disburse
                    </AppButton>
                </template>
            </Modal>

            <Modal
                :open="concludeOpen"
                size="lg"
                :persistent="concludeForm.processing"
                title="Conclude the trading session"
                :description="`This posts the whole of ${preview?.month_label ?? month?.label}. It cannot be undone.`"
                @close="concludeOpen = false"
            >
                <div v-if="preview" class="space-y-4">
                    <ul
                        class="divide-y divide-border rounded-lg border border-border"
                    >
                        <li
                            class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                        >
                            <span>Savings deposits</span>
                            <span class="tabular font-semibold"
                                >{{ preview.deposits.count }} totalling
                                {{
                                    formatMoney(preview.deposits.total_ngwee)
                                }}</span
                            >
                        </li>
                        <li
                            class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                        >
                            <span>Loan repayments</span>
                            <span class="tabular font-semibold"
                                >{{ preview.repayments.count }} totalling
                                {{
                                    formatMoney(preview.repayments.total_ngwee)
                                }}</span
                            >
                        </li>
                        <li
                            class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                        >
                            <span>Interest charged</span>
                            <span class="tabular font-semibold"
                                >{{ preview.interest.count }} loan{{
                                    preview.interest.count === 1 ? '' : 's'
                                }}</span
                            >
                        </li>
                        <li
                            class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                        >
                            <span
                                >Missed installments<span
                                    v-if="
                                        preview.missed_installments.month_label
                                    "
                                    class="text-muted-foreground"
                                >
                                    ({{
                                        preview.missed_installments.month_label
                                    }})</span
                                ></span
                            >
                            <span class="tabular font-semibold">{{
                                preview.missed_installments.count
                            }}</span>
                        </li>
                        <li
                            class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                        >
                            <span>Late payment penalties</span>
                            <span class="tabular font-semibold"
                                >{{ preview.late_penalties.count }} member{{
                                    preview.late_penalties.count === 1
                                        ? ''
                                        : 's'
                                }}, {{ preview.late_penalties.days }} day{{
                                    preview.late_penalties.days === 1 ? '' : 's'
                                }}</span
                            >
                        </li>
                    </ul>

                    <p
                        v-if="preview.unreceived.count > 0"
                        class="rounded-lg border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                    >
                        {{ preview.unreceived.count }} member{{
                            preview.unreceived.count === 1 ? '' : 's'
                        }}
                        declared but have not been marked as paid. Nothing will
                        be posted for them, and their installments will be
                        treated as missed.
                    </p>

                    <p class="text-xs text-muted-foreground">
                        All of it posts in one transaction. If any single line
                        cannot be posted the whole month is rolled back and
                        nothing changes.
                    </p>
                </div>

                <template #footer>
                    <AppButton
                        variant="ghost"
                        :disabled="concludeForm.processing"
                        @click="concludeOpen = false"
                    >
                        Cancel
                    </AppButton>
                    <AppButton
                        :loading="concludeForm.processing"
                        @click="conclude"
                    >
                        Post the month
                    </AppButton>
                </template>
            </Modal>
        </ClientOnly>
    </AdminLayout>
</template>
