<script setup lang="ts">
/**
 * Confirmation before a consequential action.
 *
 * The `dual-approval` variant implements the group's two-person rule: a second
 * committee member must enter their own credentials before the action proceeds.
 * Those credentials are posted to the server, which verifies them and checks that
 * the second approver is a different user with the right permission. Nothing here
 * decides whether the pair is valid — this only collects the second signature.
 */
import { ShieldCheck, TriangleAlert } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

import AppButton from './AppButton.vue';
import FormField from './FormField.vue';
import Modal from './Modal.vue';
import TextInput from './TextInput.vue';

const props = withDefaults(
    defineProps<{
        title: string;
        message?: string;
        confirmLabel?: string;
        cancelLabel?: string;
        variant?: 'default' | 'destructive' | 'dual-approval';
        /** Shown in the dual-approval panel, e.g. "Disburse K12,000.00 to Bertha Chileshe". */
        actionSummary?: string;
        /** Server-side errors keyed by field, e.g. { approver_email: '…' }. */
        errors?: Record<string, string>;
        processing?: boolean;
    }>(),
    {
        confirmLabel: 'Confirm',
        cancelLabel: 'Cancel',
        variant: 'default',
    },
);

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{
    confirm: [payload: { approver_email?: string; approver_password?: string }];
    cancel: [];
}>();

const approverEmail = ref('');
const approverPassword = ref('');

const isDual = computed(() => props.variant === 'dual-approval');
const isDestructive = computed(() => props.variant === 'destructive');

const canConfirm = computed(() => {
    if (props.processing) {
        return false;
    }

    return isDual.value
        ? approverEmail.value.length > 0 && approverPassword.value.length > 0
        : true;
});

/** Never leave a second approver's password sitting in memory after the dialog closes. */
watch(open, (isOpen) => {
    if (!isOpen) {
        approverEmail.value = '';
        approverPassword.value = '';
    }
});

function confirm(): void {
    if (!canConfirm.value) {
        return;
    }

    emit(
        'confirm',
        isDual.value
            ? {
                  approver_email: approverEmail.value,
                  approver_password: approverPassword.value,
              }
            : {},
    );
}

function cancel(): void {
    open.value = false;
    emit('cancel');
}
</script>

<template>
    <Modal
        v-model:open="open"
        :title="title"
        size="sm"
        :persistent="processing"
        @close="cancel"
    >
        <div class="space-y-4">
            <div class="flex gap-3">
                <span
                    v-if="isDestructive || isDual"
                    :class="[
                        'grid size-9 shrink-0 place-items-center rounded-full',
                        isDestructive
                            ? 'bg-red-50 text-destructive dark:bg-red-500/15'
                            : 'bg-gold-50 text-gold-700 dark:bg-gold-400/15 dark:text-gold-300',
                    ]"
                >
                    <component
                        :is="isDestructive ? TriangleAlert : ShieldCheck"
                        class="size-5"
                    />
                </span>
                <p v-if="message" class="text-sm text-muted-foreground">
                    {{ message }}
                </p>
            </div>

            <div
                v-if="isDual"
                class="space-y-3 rounded-lg border border-gold-200 bg-gold-50/50 p-4 dark:border-gold-400/25 dark:bg-gold-400/5"
            >
                <div>
                    <p class="text-sm font-semibold text-foreground">
                        Second approval required
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        A second committee member must sign this off. Ask them
                        to enter their own details — do not enter yours twice.
                    </p>
                </div>

                <p
                    v-if="actionSummary"
                    class="rounded-md bg-card px-3 py-2 text-xs font-medium text-card-foreground"
                >
                    {{ actionSummary }}
                </p>

                <FormField
                    label="Approver email"
                    :error="errors?.approver_email"
                    required
                >
                    <template #default="{ id, invalid }">
                        <TextInput
                            :id="id"
                            v-model="approverEmail"
                            type="email"
                            autocomplete="off"
                            :invalid="invalid"
                            placeholder="name@unitysavings.test"
                        />
                    </template>
                </FormField>

                <FormField
                    label="Approver password"
                    :error="errors?.approver_password"
                    required
                >
                    <template #default="{ id, invalid }">
                        <TextInput
                            :id="id"
                            v-model="approverPassword"
                            type="password"
                            autocomplete="new-password"
                            :invalid="invalid"
                        />
                    </template>
                </FormField>
            </div>

            <p
                v-if="errors?.message"
                class="text-xs font-medium text-destructive"
                role="alert"
            >
                {{ errors.message }}
            </p>
        </div>

        <template #footer>
            <AppButton variant="ghost" :disabled="processing" @click="cancel">{{
                cancelLabel
            }}</AppButton>
            <AppButton
                :variant="
                    isDestructive ? 'destructive' : isDual ? 'gold' : 'primary'
                "
                :disabled="!canConfirm"
                :loading="processing"
                @click="confirm"
            >
                {{ confirmLabel }}
            </AppButton>
        </template>
    </Modal>
</template>
