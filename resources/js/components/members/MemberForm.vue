<script setup lang="ts">
/**
 * The member details form, shared by registration and correction.
 *
 * Join date and joining fee only appear when registering: both are settled at that
 * moment and changing them later would rewrite which fee tier the member joined
 * under. The K2,000 late-registration minimum surfaces as soon as the chosen join
 * date lands in the cycle's third month.
 */
import { computed } from 'vue';

import {
    CheckboxInput,
    FormField,
    MoneyInput,
    TextareaInput,
    TextInput,
} from '@/components/unity';
import { formatMoney } from '@/lib/money';
import type {
    EnumOption,
    MemberFormData,
    RegistrationState,
} from '@/types/members';
import NextOfKinRepeater from './NextOfKinRepeater.vue';

const props = defineProps<{
    errors: Partial<Record<string, string>>;
    relationships: EnumOption[];
    registration: RegistrationState;
    mode: 'create' | 'edit';
}>();

const form = defineModel<MemberFormData>({ required: true });

const isCreate = computed(() => props.mode === 'create');

/** Which month of the cycle the chosen join date falls in, counting from one. */
const monthSequence = computed<number | null>(() => {
    if (!form.value.joined_on || !props.registration.cycle_starts_on) {
        return null;
    }

    const start = new Date(props.registration.cycle_starts_on);
    const joined = new Date(form.value.joined_on);

    if (Number.isNaN(joined.getTime())) {
        return null;
    }

    const months =
        (joined.getFullYear() - start.getFullYear()) * 12 +
        (joined.getMonth() - start.getMonth());

    return months + 1;
});

const isLateRegistration = computed(
    () =>
        monthSequence.value !== null &&
        props.registration.late_registration_month !== undefined &&
        monthSequence.value >= props.registration.late_registration_month,
);

const minimumFeeNgwee = computed(() =>
    isLateRegistration.value
        ? (props.registration.late_fee_ngwee ?? 0)
        : (props.registration.standard_fee_ngwee ?? 0),
);
</script>

<template>
    <div class="space-y-6">
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField
                label="Full name"
                :error="errors.full_name"
                required
                class="sm:col-span-2"
            >
                <template #default="{ id, invalid }">
                    <TextInput
                        :id="id"
                        v-model="form.full_name"
                        :invalid="invalid"
                        autocomplete="name"
                    />
                </template>
            </FormField>

            <FormField
                label="NRC number"
                hint="Format 123456/78/9."
                :error="errors.nrc_number"
                required
            >
                <template #default="{ id, invalid }">
                    <TextInput
                        :id="id"
                        v-model="form.nrc_number"
                        :invalid="invalid"
                        placeholder="123456/78/9"
                    />
                </template>
            </FormField>

            <FormField label="Phone" :error="errors.phone">
                <template #default="{ id, invalid }">
                    <TextInput
                        :id="id"
                        v-model="form.phone"
                        :invalid="invalid"
                        placeholder="09…"
                    />
                </template>
            </FormField>

            <FormField
                label="Physical address"
                :error="errors.physical_address"
                class="sm:col-span-2"
            >
                <template #default="{ id, invalid }">
                    <TextareaInput
                        :id="id"
                        v-model="form.physical_address"
                        :invalid="invalid"
                    />
                </template>
            </FormField>

            <CheckboxInput
                v-model="form.is_diaspora"
                label="Lives outside Zambia"
                hint="Diaspora members transact by transfer."
                class="sm:col-span-2"
            />
        </div>

        <div
            v-if="isCreate"
            class="grid gap-4 border-t border-border pt-6 sm:grid-cols-2"
        >
            <FormField label="Date joined" :error="errors.joined_on" required>
                <template #default="{ id, invalid }">
                    <TextInput
                        :id="id"
                        v-model="form.joined_on"
                        type="date"
                        :invalid="invalid"
                        :min="registration.cycle_starts_on"
                        :max="registration.cycle_ends_on"
                    />
                </template>
            </FormField>

            <FormField
                label="Joining fee paid"
                :error="errors.joining_fee_ngwee"
                :hint="`Minimum ${formatMoney(minimumFeeNgwee)}.`"
                required
            >
                <template #default="{ id, invalid }">
                    <MoneyInput
                        :id="id"
                        v-model="form.joining_fee_ngwee"
                        :min="minimumFeeNgwee"
                        :invalid="invalid"
                    />
                </template>
            </FormField>

            <p
                v-if="isLateRegistration"
                class="rounded-lg border border-gold-200 bg-gold-50/60 px-4 py-3 text-sm text-gold-900 sm:col-span-2 dark:border-gold-400/25 dark:bg-gold-400/5 dark:text-gold-200"
            >
                <span class="font-semibold">Late registration.</span>
                Joining in month {{ monthSequence }} of the cycle, so the fee is
                at least
                {{ formatMoney(registration.late_fee_ngwee ?? 0) }} instead of
                {{ formatMoney(registration.standard_fee_ngwee ?? 0) }}.
            </p>
        </div>

        <div class="border-t border-border pt-6">
            <CheckboxInput
                v-model="form.joining_fee_paid"
                label="Joining fee received"
            />
        </div>

        <div class="border-t border-border pt-6">
            <h3 class="text-sm font-semibold text-foreground">Next of kin</h3>
            <p class="mt-1 mb-4 text-sm text-muted-foreground">
                Who the group contacts, and pays out to, if the member cannot
                act for themselves.
            </p>

            <NextOfKinRepeater
                v-model="form.next_of_kin"
                :relationships="relationships"
                :errors="errors as Record<string, string>"
            />
        </div>
    </div>
</template>
