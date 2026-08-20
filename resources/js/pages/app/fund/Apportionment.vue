<script setup lang="ts">
/**
 * The diaspora split calculator and the checklist of transfers it produces.
 *
 * The preview is fetched from the server rather than divided here, so what the
 * committee approves on screen is exactly the arithmetic that will be written. The
 * remainder that will not divide into whole ngwee stays in the fund, and the preview
 * says so before anyone confirms.
 */
import { useForm } from '@inertiajs/vue3';
import { Globe, Info, Wallet } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

import {
    AppButton,
    AppCard,
    Can,
    ClientOnly,
    ConfirmDialog,
    EmptyState,
    FormField,
    Modal,
    MoneyInput,
    StatCard,
    StatusBadge,
    TextInput,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type {
    Apportionment,
    ApportionmentItem,
    ApportionmentPreview,
} from '@/types/fund';

const props = defineProps<{
    apportionments: Apportionment[];
    recipients: {
        member_id: number;
        member_number: number;
        full_name: string;
    }[];
    balance_ngwee: number;
    abilities: { create: boolean };
}>();

const today = new Date().toISOString().slice(0, 10);

const total = ref<number | null>(null);
const preview = ref<ApportionmentPreview | null>(null);
const previewing = ref(false);
const confirmOpen = ref(false);
const confirming = ref<ApportionmentItem | null>(null);

const form = useForm({
    total_ngwee: null as number | null,
    declared_on: today,
    note: '',
    approver_email: '',
    approver_password: '',
});

const transferForm = useForm({ paid_on: today, reference: '' });

const pendingCount = computed(() =>
    props.apportionments.reduce(
        (count, apportionment) =>
            count +
            apportionment.items.filter((item) => item.status === 'pending')
                .length,
        0,
    ),
);

/**
 * Ask the server to divide; the screen never does the arithmetic itself.
 *
 * The preview endpoint answers with JSON rather than an Inertia page, so it is
 * fetched directly instead of through a visit.
 */
watch(total, async (value) => {
    if (!value || value <= 0) {
        preview.value = null;

        return;
    }

    previewing.value = true;

    const response = await fetch('/app/fund/apportionment/preview', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN':
                document
                    .querySelector('meta[name="csrf-token"]')
                    ?.getAttribute('content') ?? '',
        },
        body: JSON.stringify({ total_ngwee: value }),
        credentials: 'same-origin',
    });

    preview.value = response.ok ? await response.json() : null;
    previewing.value = false;
});

function confirmSplit(payload: {
    approver_email?: string;
    approver_password?: string;
}): void {
    form.total_ngwee = total.value;
    form.approver_email = payload.approver_email ?? '';
    form.approver_password = payload.approver_password ?? '';

    form.post('/app/fund/apportionment', {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
            total.value = null;
            preview.value = null;
            form.reset();
        },
    });
}

function submitTransfer(): void {
    if (!confirming.value) {
        return;
    }

    transferForm.post(
        `/app/fund/apportionment/items/${confirming.value.id}/confirm`,
        {
            preserveScroll: true,
            onSuccess: () => {
                confirming.value = null;
                transferForm.reset('reference');
            },
        },
    );
}
</script>

<template>
    <AdminLayout
        title="Diaspora apportionment"
        heading="Diaspora apportionment"
        description="An equal split across the members living abroad, paid transfer by transfer."
    >
        <div class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Fund balance"
                    :ngwee="balance_ngwee"
                    :icon="Wallet"
                    accent="gold"
                />
                <StatCard
                    label="Members abroad"
                    :value="recipients.length"
                    :icon="Globe"
                />
                <StatCard
                    label="Transfers to send"
                    :value="pendingCount"
                    :icon="Info"
                    hint="Each one debits the fund when ticked"
                />
            </div>

            <AppCard
                title="Split calculator"
                description="Enter the total. The server divides it and tells you what will not divide."
            >
                <div class="grid gap-5 lg:grid-cols-2">
                    <div class="space-y-4">
                        <FormField
                            label="Total to apportion"
                            :hint="`The fund holds ${formatMoney(balance_ngwee)}.`"
                        >
                            <MoneyInput v-model="total" :steppers="false" />
                        </FormField>

                        <p
                            class="flex gap-2 rounded-lg bg-muted px-3 py-2.5 text-xs text-muted-foreground"
                        >
                            <Info class="mt-0.5 size-3.5 shrink-0" />
                            <span>
                                The split is floor division: nobody receives a
                                ngwee more than anybody else, and whatever will
                                not divide stays in the fund.
                            </span>
                        </p>

                        <Can permission="fund.approve-outflow">
                            <AppButton
                                :disabled="!preview || preview.share_ngwee <= 0"
                                @click="confirmOpen = true"
                            >
                                Confirm this split
                            </AppButton>
                        </Can>
                    </div>

                    <div>
                        <div
                            v-if="!preview"
                            class="rounded-lg border border-dashed border-border px-4 py-8 text-center text-sm text-muted-foreground"
                        >
                            {{
                                previewing
                                    ? 'Working out the split…'
                                    : "Enter a total to preview each member's share."
                            }}
                        </div>

                        <div v-else class="space-y-3">
                            <dl class="grid grid-cols-3 gap-3 text-sm">
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Each
                                    </dt>
                                    <dd class="tabular font-semibold">
                                        {{ formatMoney(preview.share_ngwee) }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Apportioned
                                    </dt>
                                    <dd class="tabular font-semibold">
                                        {{
                                            formatMoney(
                                                preview.apportioned_ngwee,
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Stays in the fund
                                    </dt>
                                    <dd class="tabular font-semibold">
                                        {{
                                            formatMoney(preview.remainder_ngwee)
                                        }}
                                    </dd>
                                </div>
                            </dl>

                            <ul
                                class="divide-y divide-border rounded-lg border border-border"
                            >
                                <li
                                    v-for="recipient in preview.recipients"
                                    :key="recipient.member_id"
                                    class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                                >
                                    <span class="truncate">
                                        {{ recipient.member_number }}.
                                        {{ recipient.full_name }}
                                    </span>
                                    <span class="tabular font-medium">
                                        {{
                                            formatMoney(recipient.amount_ngwee)
                                        }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </AppCard>

            <AppCard
                title="Declared splits"
                description="Tick each transfer off as it is sent. That is what debits the fund."
                flush
            >
                <div v-if="apportionments.length === 0" class="p-5">
                    <EmptyState
                        title="Nothing apportioned yet"
                        description="Confirmed splits and their transfer checklists appear here."
                        :icon="Globe"
                    />
                </div>

                <div v-else class="divide-y divide-border">
                    <section
                        v-for="apportionment in apportionments"
                        :key="apportionment.id"
                        class="px-5 py-4"
                    >
                        <header
                            class="flex flex-wrap items-baseline justify-between gap-2"
                        >
                            <div>
                                <h3 class="text-sm font-semibold">
                                    {{
                                        formatMoney(
                                            apportionment.apportioned_ngwee,
                                        )
                                    }}
                                    across
                                    {{ apportionment.items.length }} members
                                </h3>
                                <p class="text-xs text-muted-foreground">
                                    Declared {{ apportionment.declared_on }} ·
                                    {{ apportionment.recorded_by }} +
                                    {{ apportionment.second_approver }} ·
                                    {{
                                        formatMoney(
                                            apportionment.remainder_ngwee,
                                        )
                                    }}
                                    stayed in the fund
                                </p>
                            </div>
                        </header>

                        <ul class="mt-3 space-y-1.5">
                            <li
                                v-for="item in apportionment.items"
                                :key="item.id"
                                class="flex flex-wrap items-center gap-3 rounded-lg bg-muted/50 px-3 py-2"
                            >
                                <span class="min-w-0 flex-1 truncate text-sm">
                                    {{ item.member }}
                                </span>

                                <span class="tabular text-sm font-medium">
                                    {{ formatMoney(item.amount_ngwee) }}
                                </span>

                                <StatusBadge
                                    :status="item.status"
                                    :label="item.status_label"
                                    size="sm"
                                />

                                <span
                                    v-if="item.reference"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ item.reference }}
                                </span>

                                <AppButton
                                    v-if="item.abilities.confirmTransfer"
                                    size="sm"
                                    variant="outline"
                                    @click="confirming = item"
                                >
                                    Mark sent
                                </AppButton>
                            </li>
                        </ul>
                    </section>
                </div>
            </AppCard>
        </div>

        <ClientOnly>
            <ConfirmDialog
                v-model:open="confirmOpen"
                variant="dual-approval"
                title="Confirm this apportionment"
                :action-summary="
                    preview
                        ? `${formatMoney(preview.share_ngwee)} each to ${preview.recipients.length} members abroad`
                        : undefined
                "
                message="A second committee member must confirm. Neither of you may be receiving a share."
                confirm-label="Confirm split"
                :errors="form.errors as Record<string, string>"
                :processing="form.processing"
                @confirm="confirmSplit"
            />

            <Modal
                :open="confirming !== null"
                title="Confirm the transfer"
                description="This is what debits the fund. Record the reference the bank or wallet gave you."
                @close="confirming = null"
            >
                <div class="space-y-4">
                    <FormField
                        label="Sent on"
                        :error="transferForm.errors.paid_on"
                    >
                        <input
                            v-model="transferForm.paid_on"
                            type="date"
                            class="h-10 w-full rounded-lg border border-input bg-card px-3 text-sm"
                        />
                    </FormField>

                    <FormField
                        label="Reference"
                        :error="transferForm.errors.reference"
                        hint="Optional — the transfer or wallet reference."
                    >
                        <TextInput v-model="transferForm.reference" />
                    </FormField>
                </div>

                <template #footer>
                    <AppButton variant="ghost" @click="confirming = null">
                        Cancel
                    </AppButton>
                    <AppButton
                        :loading="transferForm.processing"
                        @click="submitTransfer"
                    >
                        Mark sent
                    </AppButton>
                </template>
            </Modal>
        </ClientOnly>
    </AdminLayout>
</template>
