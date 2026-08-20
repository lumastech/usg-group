<script setup lang="ts">
/**
 * Records a change to a member's standing.
 *
 * Only the transitions the server allows from the member's current status are
 * offered. Expulsion reveals the grounds list and death the date field, because
 * the domain refuses either without them.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

import {
    AppButton,
    FormField,
    Modal,
    SelectInput,
    TextareaInput,
    TextInput,
} from '@/components/unity';
import { MemberStatus } from '@/types/enums';
import type { EnumOption, Member } from '@/types/members';

const props = defineProps<{
    member: Member;
    transitions: EnumOption[];
    expulsionGrounds: EnumOption[];
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    status: props.transitions[0]?.value ?? '',
    reason: '',
    effective_on: '',
    expulsion_ground: '',
    date_of_death: '',
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

const isExpulsion = computed(() => form.status === MemberStatus.Expelled);
const isDeath = computed(() => form.status === MemberStatus.Deceased);

const destructive = computed(() => isExpulsion.value || isDeath.value);

function submit(): void {
    form.put(`/app/members/${props.member.id}/status`, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Modal
        v-model:open="open"
        title="Change status"
        :description="`${member.full_name} is currently ${member.status_label}.`"
        size="sm"
    >
        <div class="space-y-4">
            <FormField label="New status" :error="form.errors.status" required>
                <template #default="{ id, invalid }">
                    <SelectInput
                        :id="id"
                        v-model="form.status"
                        :options="transitions"
                        :invalid="invalid"
                    />
                </template>
            </FormField>

            <FormField
                v-if="isExpulsion"
                label="Ground for expulsion"
                :error="form.errors.expulsion_ground"
                required
            >
                <template #default="{ id, invalid }">
                    <SelectInput
                        :id="id"
                        v-model="form.expulsion_ground"
                        :options="expulsionGrounds"
                        placeholder="Choose a ground"
                        :invalid="invalid"
                    />
                </template>
            </FormField>

            <FormField
                v-if="isDeath"
                label="Date of death"
                :error="form.errors.date_of_death"
                required
            >
                <template #default="{ id, invalid }">
                    <TextInput
                        :id="id"
                        v-model="form.date_of_death"
                        type="date"
                        :invalid="invalid"
                    />
                </template>
            </FormField>

            <FormField
                v-else
                label="Effective from"
                hint="Leave blank to record it as taking effect today."
                :error="form.errors.effective_on"
            >
                <template #default="{ id, invalid }">
                    <TextInput
                        :id="id"
                        v-model="form.effective_on"
                        type="date"
                        :invalid="invalid"
                    />
                </template>
            </FormField>

            <FormField
                label="Reason"
                hint="Kept on the member's record."
                :error="form.errors.reason"
            >
                <template #default="{ id, invalid }">
                    <TextareaInput
                        :id="id"
                        v-model="form.reason"
                        :invalid="invalid"
                    />
                </template>
            </FormField>
        </div>

        <template #footer>
            <AppButton variant="ghost" @click="open = false">Cancel</AppButton>
            <AppButton
                :variant="destructive ? 'destructive' : 'primary'"
                :loading="form.processing"
                @click="submit"
            >
                Record change
            </AppButton>
        </template>
    </Modal>
</template>
