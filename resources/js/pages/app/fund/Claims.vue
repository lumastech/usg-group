<script setup lang="ts">
/**
 * The two claim registers: funeral grants and unity baby grants.
 *
 * Both run Submitted → Approved → Paid, so both render from one column set and one
 * pair of dialogs. The funeral form's relationship select is fed from the server's
 * own enum, which has exactly the three cases the constitution allows.
 */
import { useForm } from '@inertiajs/vue3';
import { Baby, HeartHandshake, Info, Wallet } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    Can,
    ClientOnly,
    ConfirmDialog,
    DataTable,
    FormField,
    Modal,
    SelectInput,
    StatCard,
    StatusBadge,
    TextareaInput,
    TextInput,
} from '@/components/unity';
import type { Column, SelectOption } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { formatMoney } from '@/lib/money';
import type { FundRules, GrantClaim } from '@/types/fund';

const props = defineProps<{
    funeralClaims: GrantClaim[];
    babyClaims: GrantClaim[];
    members: SelectOption[];
    relationships: SelectOption[];
    balance_ngwee: number;
    rules: FundRules;
    abilities: { create: boolean };
}>();

const today = new Date().toISOString().slice(0, 10);

const funeralOpen = ref(false);
const babyOpen = ref(false);
const rejectOpen = ref(false);

/** The claim a dual-approval dialog is currently about, and which step it is. */
const acting = ref<{ claim: GrantClaim; step: 'approve' | 'pay' } | null>(null);
const rejecting = ref<GrantClaim | null>(null);

const funeralForm = useForm({
    member_id: null as number | null,
    deceased_name: '',
    relationship: null as string | null,
    claim_date: today,
    note: '',
});

const babyForm = useForm({
    member_id: null as number | null,
    child_name: '',
    born_on: today,
    claim_date: today,
    note: '',
});

const actionForm = useForm({
    occurred_on: today,
    approver_email: '',
    approver_password: '',
});

const rejectForm = useForm({ reason: '' });

const columns = computed<Column<GrantClaim>[]>(() => [
    { key: 'member', label: 'Member' },
    { key: 'detail', label: 'Claim for' },
    { key: 'claim_date', label: 'Claimed', hideOnMobile: true },
    { key: 'amount_ngwee', label: 'Amount', numeric: true },
    { key: 'status', label: 'Status' },
    { key: 'signatures', label: 'Signatures', hideOnMobile: true },
    { key: 'actions', label: '', align: 'right' },
]);

const openCount = computed(
    () =>
        [...props.funeralClaims, ...props.babyClaims].filter((claim) =>
            ['submitted', 'approved'].includes(claim.status),
        ).length,
);

const paidTotal = computed(() =>
    [...props.funeralClaims, ...props.babyClaims]
        .filter((claim) => claim.status === 'paid')
        .reduce((sum, claim) => sum + claim.amount_ngwee, 0),
);

/** Where each dual-approval post goes, given the claim and the step. */
function endpoint(claim: GrantClaim, step: string): string {
    const kind = claim.grant === 'funeral' ? 'funeral' : 'baby';

    return `/app/fund/claims/${kind}/${claim.id}/${step}`;
}

function act(claim: GrantClaim, step: 'approve' | 'pay'): void {
    acting.value = { claim, step };
}

function confirmAction(payload: {
    approver_email?: string;
    approver_password?: string;
}): void {
    if (!acting.value) {
        return;
    }

    actionForm.approver_email = payload.approver_email ?? '';
    actionForm.approver_password = payload.approver_password ?? '';

    actionForm.post(endpoint(acting.value.claim, acting.value.step), {
        preserveScroll: true,
        onSuccess: () => {
            acting.value = null;
            actionForm.reset('approver_email', 'approver_password');
        },
    });
}

function openReject(claim: GrantClaim): void {
    rejecting.value = claim;
    rejectOpen.value = true;
}

function submitReject(): void {
    if (!rejecting.value) {
        return;
    }

    rejectForm.post(endpoint(rejecting.value, 'reject'), {
        preserveScroll: true,
        onSuccess: () => {
            rejectOpen.value = false;
            rejecting.value = null;
            rejectForm.reset();
        },
    });
}

function submitFuneral(): void {
    funeralForm.post('/app/fund/claims/funeral', {
        preserveScroll: true,
        onSuccess: () => {
            funeralOpen.value = false;
            funeralForm.reset();
        },
    });
}

function submitBaby(): void {
    babyForm.post('/app/fund/claims/baby', {
        preserveScroll: true,
        onSuccess: () => {
            babyOpen.value = false;
            babyForm.reset();
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Fund claims"
        heading="Fund claims"
        description="Funeral and unity baby grants. Two committee signatures before anything is paid."
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
                    label="Open claims"
                    :value="openCount"
                    :icon="HeartHandshake"
                    hint="Submitted or approved"
                />
                <StatCard
                    label="Paid this cycle"
                    :ngwee="paidTotal"
                    :icon="Baby"
                />
            </div>

            <AppCard
                title="Funeral grants"
                :description="`${formatMoney(rules.funeral_grant_ngwee)} for the death of a member's parent, spouse or child.`"
                flush
            >
                <template #actions>
                    <Can permission="fund.record">
                        <AppButton size="sm" @click="funeralOpen = true">
                            Record a claim
                        </AppButton>
                    </Can>
                </template>

                <DataTable
                    :rows="funeralClaims"
                    :columns="columns"
                    empty-title="No funeral claims"
                    empty-description="Claims raised by members appear here for the committee."
                >
                    <template #cell-amount_ngwee="{ row }">
                        <span class="tabular">{{
                            formatMoney(row.amount_ngwee)
                        }}</span>
                    </template>

                    <template #cell-status="{ row }">
                        <StatusBadge
                            :status="row.status"
                            :label="row.status_label"
                            size="sm"
                        />
                    </template>

                    <template #cell-signatures="{ row }">
                        <span class="text-xs text-muted-foreground">
                            <template v-if="row.first_approver">
                                {{ row.first_approver }} +
                                {{ row.second_approver }}
                            </template>
                            <template v-else>—</template>
                        </span>
                    </template>

                    <template #cell-actions="{ row }">
                        <div class="flex justify-end gap-2">
                            <AppButton
                                v-if="row.abilities.approve"
                                size="sm"
                                variant="outline"
                                @click="act(row, 'approve')"
                            >
                                Approve
                            </AppButton>
                            <AppButton
                                v-if="row.abilities.pay"
                                size="sm"
                                @click="act(row, 'pay')"
                            >
                                Pay
                            </AppButton>
                            <AppButton
                                v-if="row.abilities.reject"
                                size="sm"
                                variant="ghost"
                                @click="openReject(row)"
                            >
                                Reject
                            </AppButton>
                        </div>
                    </template>
                </DataTable>
            </AppCard>

            <AppCard
                title="Unity baby grants"
                :description="`${formatMoney(rules.unity_baby_grant_ngwee)} for a child born to a member during the cycle.`"
                flush
            >
                <template #actions>
                    <Can permission="fund.record">
                        <AppButton size="sm" @click="babyOpen = true">
                            Record a claim
                        </AppButton>
                    </Can>
                </template>

                <DataTable
                    :rows="babyClaims"
                    :columns="columns"
                    empty-title="No unity baby claims"
                    empty-description="Claims raised by members appear here for the committee."
                >
                    <template #cell-amount_ngwee="{ row }">
                        <span class="tabular">{{
                            formatMoney(row.amount_ngwee)
                        }}</span>
                    </template>

                    <template #cell-status="{ row }">
                        <StatusBadge
                            :status="row.status"
                            :label="row.status_label"
                            size="sm"
                        />
                    </template>

                    <template #cell-signatures="{ row }">
                        <span class="text-xs text-muted-foreground">
                            <template v-if="row.first_approver">
                                {{ row.first_approver }} +
                                {{ row.second_approver }}
                            </template>
                            <template v-else>—</template>
                        </span>
                    </template>

                    <template #cell-actions="{ row }">
                        <div class="flex justify-end gap-2">
                            <AppButton
                                v-if="row.abilities.approve"
                                size="sm"
                                variant="outline"
                                @click="act(row, 'approve')"
                            >
                                Approve
                            </AppButton>
                            <AppButton
                                v-if="row.abilities.pay"
                                size="sm"
                                @click="act(row, 'pay')"
                            >
                                Pay
                            </AppButton>
                            <AppButton
                                v-if="row.abilities.reject"
                                size="sm"
                                variant="ghost"
                                @click="openReject(row)"
                            >
                                Reject
                            </AppButton>
                        </div>
                    </template>
                </DataTable>
            </AppCard>
        </div>

        <ClientOnly>
            <Modal
                v-model:open="funeralOpen"
                title="Record a funeral grant claim"
                :description="`${formatMoney(rules.funeral_grant_ngwee)}, paid once the committee has signed twice.`"
            >
                <div class="space-y-4">
                    <FormField
                        label="Member"
                        :error="funeralForm.errors.member_id"
                        required
                    >
                        <SelectInput
                            v-model="funeralForm.member_id"
                            :options="members"
                            placeholder="Choose a member"
                        />
                    </FormField>

                    <FormField
                        label="Name of the deceased"
                        :error="funeralForm.errors.deceased_name"
                        required
                    >
                        <TextInput v-model="funeralForm.deceased_name" />
                    </FormField>

                    <FormField
                        label="Relationship"
                        :error="funeralForm.errors.relationship"
                        required
                    >
                        <SelectInput
                            v-model="funeralForm.relationship"
                            :options="relationships"
                            placeholder="Choose a relationship"
                        />
                    </FormField>

                    <p
                        class="flex gap-2 rounded-lg bg-muted px-3 py-2.5 text-xs text-muted-foreground"
                    >
                        <Info class="mt-0.5 size-3.5 shrink-0" />
                        <span>
                            The constitution restricts this grant to a member's
                            parent, spouse or child. There is no discretion and
                            no override — a claim for a sibling, in-law or other
                            relative cannot be recorded.
                        </span>
                    </p>

                    <FormField
                        label="Date of claim"
                        :error="funeralForm.errors.claim_date"
                        required
                    >
                        <input
                            v-model="funeralForm.claim_date"
                            type="date"
                            class="h-10 w-full rounded-lg border border-input bg-card px-3 text-sm"
                        />
                    </FormField>

                    <FormField label="Note" :error="funeralForm.errors.note">
                        <TextareaInput v-model="funeralForm.note" :rows="2" />
                    </FormField>
                </div>

                <template #footer>
                    <AppButton variant="ghost" @click="funeralOpen = false">
                        Cancel
                    </AppButton>
                    <AppButton
                        :loading="funeralForm.processing"
                        @click="submitFuneral"
                    >
                        Submit claim
                    </AppButton>
                </template>
            </Modal>

            <Modal
                v-model:open="babyOpen"
                title="Record a unity baby grant claim"
                :description="`${formatMoney(rules.unity_baby_grant_ngwee)}, paid once the committee has signed twice.`"
            >
                <div class="space-y-4">
                    <FormField
                        label="Member"
                        :error="babyForm.errors.member_id"
                        required
                    >
                        <SelectInput
                            v-model="babyForm.member_id"
                            :options="members"
                            placeholder="Choose a member"
                        />
                    </FormField>

                    <FormField
                        label="Child's name"
                        :error="babyForm.errors.child_name"
                        hint="Optional, if the child is not yet named."
                    >
                        <TextInput v-model="babyForm.child_name" />
                    </FormField>

                    <FormField
                        label="Date of birth"
                        :error="babyForm.errors.born_on"
                        required
                    >
                        <input
                            v-model="babyForm.born_on"
                            type="date"
                            class="h-10 w-full rounded-lg border border-input bg-card px-3 text-sm"
                        />
                    </FormField>

                    <FormField
                        label="Date of claim"
                        :error="babyForm.errors.claim_date"
                        required
                    >
                        <input
                            v-model="babyForm.claim_date"
                            type="date"
                            class="h-10 w-full rounded-lg border border-input bg-card px-3 text-sm"
                        />
                    </FormField>

                    <FormField label="Note" :error="babyForm.errors.note">
                        <TextareaInput v-model="babyForm.note" :rows="2" />
                    </FormField>
                </div>

                <template #footer>
                    <AppButton variant="ghost" @click="babyOpen = false">
                        Cancel
                    </AppButton>
                    <AppButton
                        :loading="babyForm.processing"
                        @click="submitBaby"
                    >
                        Submit claim
                    </AppButton>
                </template>
            </Modal>

            <ConfirmDialog
                :open="acting !== null"
                variant="dual-approval"
                :title="
                    acting?.step === 'pay'
                        ? 'Pay this grant'
                        : 'Approve this claim'
                "
                :action-summary="
                    acting
                        ? `${acting.step === 'pay' ? 'Pay' : 'Approve'} ${formatMoney(acting.claim.amount_ngwee)} for ${acting.claim.member} — ${acting.claim.detail}`
                        : undefined
                "
                message="A second committee member, who is not the claimant, must confirm here."
                :confirm-label="
                    acting?.step === 'pay' ? 'Pay grant' : 'Approve'
                "
                :errors="actionForm.errors as Record<string, string>"
                :processing="actionForm.processing"
                @confirm="confirmAction"
                @cancel="acting = null"
            />

            <Modal
                v-model:open="rejectOpen"
                title="Reject this claim"
                description="The reason is kept on the claim and shown to the member."
            >
                <FormField
                    label="Reason"
                    :error="rejectForm.errors.reason"
                    required
                >
                    <TextareaInput v-model="rejectForm.reason" :rows="3" />
                </FormField>

                <template #footer>
                    <AppButton variant="ghost" @click="rejectOpen = false">
                        Cancel
                    </AppButton>
                    <AppButton
                        variant="destructive"
                        :loading="rejectForm.processing"
                        @click="submitReject"
                    >
                        Reject claim
                    </AppButton>
                </template>
            </Modal>
        </ClientOnly>
    </AdminLayout>
</template>
