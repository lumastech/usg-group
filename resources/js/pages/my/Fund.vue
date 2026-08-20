<script setup lang="ts">
/**
 * A member's own corner of the Social Fund: whether their K250 is in, what the fund
 * has paid them, and the claims they have raised.
 *
 * Everything here is scoped to the signed-in member on the server, so there is no id
 * to point at somebody else and nothing to hide on the client.
 */
import { useForm } from '@inertiajs/vue3';
import { Baby, CheckCircle2, HeartHandshake, Info } from '@lucide/vue';
import { ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    EmptyState,
    FormField,
    Modal,
    SelectInput,
    StatCard,
    StatusBadge,
    TextareaInput,
    TextInput,
} from '@/components/unity';
import type { SelectOption } from '@/components/unity';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import { formatMoney } from '@/lib/money';
import type { FundEntry, FundRules, GrantClaim } from '@/types/fund';

const props = defineProps<{
    member: { id: number; full_name: string; member_number: number } | null;
    contribution: { paid: boolean; expected_ngwee: number } | null;
    entries: FundEntry[];
    funeralClaims: GrantClaim[];
    babyClaims: GrantClaim[];
    relationships: SelectOption[];
    rules: FundRules | null;
}>();

const today = new Date().toISOString().slice(0, 10);

const funeralOpen = ref(false);
const babyOpen = ref(false);

const funeralForm = useForm({
    member_id: props.member?.id ?? null,
    deceased_name: '',
    relationship: null as string | null,
    claim_date: today,
    note: '',
});

const babyForm = useForm({
    member_id: props.member?.id ?? null,
    child_name: '',
    born_on: today,
    claim_date: today,
    note: '',
});

function submitFuneral(): void {
    funeralForm.post('/my/fund/claims/funeral', {
        preserveScroll: true,
        onSuccess: () => {
            funeralOpen.value = false;
            funeralForm.reset('deceased_name', 'relationship', 'note');
        },
    });
}

function submitBaby(): void {
    babyForm.post('/my/fund/claims/baby', {
        preserveScroll: true,
        onSuccess: () => {
            babyOpen.value = false;
            babyForm.reset('child_name', 'note');
        },
    });
}
</script>

<template>
    <MemberLayout title="Social fund" heading="Social fund">
        <div v-if="!member || !contribution || !rules" class="py-10">
            <EmptyState
                title="Nothing to show yet"
                description="Your login is not linked to a member record in an active cycle."
                :icon="HeartHandshake"
            />
        </div>

        <div v-else class="space-y-5">
            <StatCard
                :label="
                    contribution.paid
                        ? 'Your contribution is in'
                        : 'Your contribution is due'
                "
                :ngwee="contribution.expected_ngwee"
                :icon="contribution.paid ? CheckCircle2 : Info"
                :accent="contribution.paid ? 'brand' : 'gold'"
                :hint="
                    contribution.paid
                        ? 'Paid once for the whole cycle'
                        : 'Paid once, in full, to a treasurer'
                "
            />

            <AppCard
                title="Claim on the fund"
                description="The committee signs twice before anything is paid."
            >
                <div class="grid gap-3 sm:grid-cols-2">
                    <AppButton
                        variant="outline"
                        block
                        @click="funeralOpen = true"
                    >
                        <HeartHandshake class="size-4" />
                        Funeral grant ·
                        {{ formatMoney(rules.funeral_grant_ngwee) }}
                    </AppButton>

                    <AppButton variant="outline" block @click="babyOpen = true">
                        <Baby class="size-4" />
                        Unity baby grant ·
                        {{ formatMoney(rules.unity_baby_grant_ngwee) }}
                    </AppButton>
                </div>
            </AppCard>

            <AppCard title="Your claims" flush>
                <div
                    v-if="funeralClaims.length === 0 && babyClaims.length === 0"
                    class="p-5"
                >
                    <EmptyState
                        title="No claims yet"
                        description="Anything you claim appears here with its progress."
                    />
                </div>

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="claim in [...funeralClaims, ...babyClaims]"
                        :key="`${claim.grant}-${claim.id}`"
                        class="flex flex-wrap items-center gap-3 px-5 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ claim.detail }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Claimed {{ claim.claim_date }}
                                <span v-if="claim.note">
                                    · {{ claim.note }}</span
                                >
                            </p>
                        </div>

                        <span class="tabular text-sm font-semibold">
                            {{ formatMoney(claim.amount_ngwee) }}
                        </span>

                        <StatusBadge
                            :status="claim.status"
                            :label="claim.status_label"
                            size="sm"
                        />
                    </li>
                </ul>
            </AppCard>

            <AppCard title="Your fund entries" flush>
                <div v-if="entries.length === 0" class="p-5">
                    <EmptyState
                        title="Nothing recorded"
                        description="Your contribution and anything the fund pays you appear here."
                    />
                </div>

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="entry in entries"
                        :key="entry.id"
                        class="flex items-center gap-3 px-5 py-3"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ entry.type_label }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ entry.occurred_on }}
                            </p>
                        </div>

                        <span
                            class="tabular text-sm font-semibold"
                            :class="
                                entry.is_outflow
                                    ? 'text-brand-700 dark:text-brand-300'
                                    : ''
                            "
                        >
                            {{ formatMoney(entry.amount_ngwee) }}
                        </span>
                    </li>
                </ul>
            </AppCard>
        </div>

        <ClientOnly>
            <Modal
                v-model:open="funeralOpen"
                title="Claim the funeral grant"
                :description="
                    rules
                        ? `${formatMoney(rules.funeral_grant_ngwee)}, paid once the committee has signed twice.`
                        : undefined
                "
            >
                <div class="space-y-4">
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
                            The constitution restricts this grant to your
                            parent, spouse or child. No other relative
                            qualifies, and the committee cannot make an
                            exception.
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
                title="Claim the unity baby grant"
                :description="
                    rules
                        ? `${formatMoney(rules.unity_baby_grant_ngwee)}, paid once the committee has signed twice.`
                        : undefined
                "
            >
                <div class="space-y-4">
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
        </ClientOnly>
    </MemberLayout>
</template>
