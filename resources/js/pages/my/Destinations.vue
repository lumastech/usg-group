<script setup lang="ts">
/**
 * Where the member's money is sent.
 *
 * A member may keep a bank account and a mobile money wallet and switch between them;
 * whichever is marked as the default is where share-out, a loan and any grant will go.
 *
 * Nothing is saved until the provider confirms whose account it is, and the name that
 * comes back is shown rather than hidden — it is the one thing a member can check for
 * themselves. A name that does not look like theirs is not refused; it is flagged, and
 * a committee member has to say it is fine before money is sent.
 */
import { router, useForm } from '@inertiajs/vue3';
import { Building2, CircleAlert, Smartphone, Trash2 } from '@lucide/vue';
import { computed } from 'vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    FormField,
    SelectInput,
    StatusBadge,
    TextInput,
} from '@/components/unity';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import type { PayoutDestination } from '@/types/payments';

const props = defineProps<{
    destinations: { data: PayoutDestination[] };
    banks: { value: string; label: string }[];
    operators: { value: string; label: string }[];
    member: { id: number; full_name: string; phone: string | null };
}>();

const form = useForm({
    type: 'mobile_money',
    phone: props.member.phone ?? '',
    operator: '',
    bank_id: '',
    account_number: '',
    make_default: true,
});

const isBank = computed(() => form.type === 'bank_account');

const active = computed(() =>
    props.destinations.data.filter((row) => !row.disabled_at),
);

function save(): void {
    form.post('/my/destinations', {
        preserveScroll: true,
        onSuccess: () => form.reset('account_number'),
    });
}

function makeDefault(destination: PayoutDestination): void {
    router.put(
        `/my/destinations/${destination.id}/default`,
        {},
        { preserveScroll: true },
    );
}

function remove(destination: PayoutDestination): void {
    router.delete(`/my/destinations/${destination.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <MemberLayout
        title="Where my money goes"
        heading="Where my money goes"
        description="Your share-out, any loan and any grant are sent to whichever account is set as the default."
    >
        <div class="space-y-4">
            <AppCard title="Your accounts" flush>
                <EmptyState
                    v-if="active.length === 0"
                    title="Nothing set up yet"
                    description="Until you add an account, anything owed to you is handed over in cash at the table."
                />

                <ul v-else class="divide-y">
                    <li
                        v-for="destination in active"
                        :key="destination.id"
                        class="space-y-2 p-4"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 gap-3">
                                <component
                                    :is="
                                        destination.type === 'bank_account'
                                            ? Building2
                                            : Smartphone
                                    "
                                    class="mt-0.5 size-5 shrink-0 text-muted-foreground"
                                />
                                <div class="min-w-0">
                                    <p class="font-medium">
                                        {{ destination.label }}
                                    </p>
                                    <p
                                        class="text-sm text-muted-foreground"
                                        :class="
                                            destination.name_matches
                                                ? ''
                                                : 'text-gold-700 dark:text-gold-300'
                                        "
                                    >
                                        In the name of
                                        {{
                                            destination.resolved_account_name ??
                                            'unknown'
                                        }}
                                    </p>
                                </div>
                            </div>

                            <StatusBadge
                                v-if="destination.is_default"
                                status="active"
                                label="Default"
                                size="sm"
                            />
                        </div>

                        <p
                            v-if="destination.needs_name_confirmation"
                            class="flex items-start gap-2 rounded-md bg-gold-50 p-2 text-xs text-gold-800 dark:bg-gold-400/10 dark:text-gold-200"
                        >
                            <CircleAlert class="mt-0.5 size-4 shrink-0" />
                            That name does not look like yours. A committee
                            member has to confirm it before money is sent here.
                        </p>

                        <p
                            v-else-if="destination.is_new"
                            class="text-xs text-muted-foreground"
                        >
                            Recently changed, so the next payment to it needs a
                            second committee signature.
                        </p>

                        <div class="flex gap-2">
                            <AppButton
                                v-if="
                                    !destination.is_default &&
                                    destination.abilities.update
                                "
                                size="sm"
                                variant="secondary"
                                @click="makeDefault(destination)"
                            >
                                Use this one
                            </AppButton>
                            <AppButton
                                v-if="destination.abilities.delete"
                                size="sm"
                                variant="ghost"
                                @click="remove(destination)"
                            >
                                <template #icon
                                    ><Trash2 class="size-4"
                                /></template>
                                Remove
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>

            <AppCard
                title="Add an account"
                description="We check the account with the provider before saving it, and tell you by text whenever this changes."
            >
                <form class="space-y-4" @submit.prevent="save">
                    <FormField label="Kind of account" :error="form.errors.type">
                        <template #default="{ id, invalid }">
                            <SelectInput
                                :id="id"
                                v-model="form.type"
                                :invalid="invalid"
                                :options="[
                                    {
                                        value: 'mobile_money',
                                        label: 'Mobile money',
                                    },
                                    {
                                        value: 'bank_account',
                                        label: 'Bank account',
                                    },
                                ]"
                            />
                        </template>
                    </FormField>

                    <template v-if="isBank">
                        <FormField label="Bank" :error="form.errors.bank_id">
                            <template #default="{ id, invalid }">
                                <SelectInput
                                    :id="id"
                                    v-model="form.bank_id"
                                    :invalid="invalid"
                                    :options="[
                                        { value: '', label: 'Choose a bank' },
                                        ...banks,
                                    ]"
                                />
                            </template>
                        </FormField>

                        <FormField
                            label="Account number"
                            :error="form.errors.account_number"
                        >
                            <template #default="{ id, invalid }">
                                <TextInput
                                    :id="id"
                                    v-model="form.account_number"
                                    :invalid="invalid"
                                    inputmode="numeric"
                                />
                            </template>
                        </FormField>
                    </template>

                    <template v-else>
                        <FormField
                            label="Mobile money number"
                            :error="form.errors.phone"
                        >
                            <template #default="{ id, invalid }">
                                <TextInput
                                    :id="id"
                                    v-model="form.phone"
                                    :invalid="invalid"
                                    inputmode="tel"
                                    placeholder="0977 000 000"
                                />
                            </template>
                        </FormField>

                        <FormField
                            label="Network"
                            hint="Leave blank and we will work it out from the number."
                            :error="form.errors.operator"
                        >
                            <template #default="{ id, invalid }">
                                <SelectInput
                                    :id="id"
                                    v-model="form.operator"
                                    :invalid="invalid"
                                    :options="[
                                        { value: '', label: 'Work it out' },
                                        ...operators,
                                    ]"
                                />
                            </template>
                        </FormField>
                    </template>

                    <AppButton type="submit" :loading="form.processing">
                        Check and save
                    </AppButton>
                </form>
            </AppCard>
        </div>
    </MemberLayout>
</template>
