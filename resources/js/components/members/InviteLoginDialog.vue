<script setup lang="ts">
/**
 * Attaches a portal login to a member and emails them the invitation.
 *
 * The address is asked for here rather than taken from the member record because
 * the commitment sheet holds no email — the treasurer types the one the member
 * gave. An address already in use is reused, not duplicated, by the server.
 */
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

import { AppButton, FormField, Modal, TextInput } from '@/components/unity';
import type { Member } from '@/types/members';

const props = defineProps<{ member: Member | null }>();

const emit = defineEmits<{ close: [] }>();

const form = useForm({ email: '' });

watch(
    () => props.member,
    (member) => {
        form.reset();
        form.clearErrors();
        form.email = member?.email ?? '';
    },
);

function submit(): void {
    if (!props.member) {
        return;
    }

    form.post(`/app/members/${props.member.id}/invite`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <Modal
        :open="member !== null"
        title="Invite to the portal"
        :description="
            member
                ? `${member.full_name} will be emailed a link to set their password.`
                : undefined
        "
        size="sm"
        @close="emit('close')"
    >
        <FormField label="Email address" :error="form.errors.email" required>
            <template #default="{ id, invalid }">
                <TextInput
                    :id="id"
                    v-model="form.email"
                    type="email"
                    :invalid="invalid"
                    autocomplete="off"
                    placeholder="name@example.com"
                    @keyup.enter="submit"
                />
            </template>
        </FormField>

        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">Cancel</AppButton>
            <AppButton :loading="form.processing" @click="submit"
                >Send invitation</AppButton
            >
        </template>
    </Modal>
</template>
