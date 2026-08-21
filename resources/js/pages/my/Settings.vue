<script setup lang="ts">
/**
 * How a member wants to hear from the group.
 *
 * The page states what will actually happen, not just what was chosen: a member who
 * picks SMS with no number on record is told the group will email them instead,
 * because that is what the server does rather than dropping the message.
 */
import { useForm } from '@inertiajs/vue3';
import { Mail, MessageSquare } from '@lucide/vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    FormField,
    SelectInput,
    TextInput,
} from '@/components/unity';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';

const props = defineProps<{
    member: {
        id: number;
        full_name: string;
        phone: string | null;
        email: string | null;
        notification_channel: string;
    } | null;
    channels: { value: string; label: string }[];
    effective: string[];
}>();

const form = useForm({
    notification_channel: props.member?.notification_channel ?? 'mail',
    phone: props.member?.phone ?? '',
});

function submit(): void {
    if (!props.member) {
        return;
    }

    form.put(`/my/settings/${props.member.id}`, { preserveScroll: true });
}
</script>

<template>
    <MemberLayout
        title="Settings"
        heading="Settings"
        description="How the group reaches you"
    >
        <AppCard v-if="!member">
            <EmptyState
                title="No member record"
                description="Your login is not linked to a member in the current cycle. Ask the treasurer to link it."
            />
        </AppCard>

        <template v-else>
            <AppCard title="Notifications">
                <p class="mb-4 text-sm text-muted-foreground">
                    The group sends you the declaration window each month, a
                    reminder if you have not declared, what your repayment is
                    before trading day, any penalty charged, and your statement
                    once the month is concluded.
                </p>

                <form class="space-y-4" @submit.prevent="submit">
                    <FormField
                        label="Send my notifications by"
                        :error="form.errors.notification_channel"
                    >
                        <SelectInput
                            v-model="form.notification_channel"
                            :options="channels"
                        />
                    </FormField>

                    <FormField
                        label="Mobile number"
                        :error="form.errors.phone"
                        hint="Texts are sent to this number. Leave it blank if you would rather not be texted."
                    >
                        <TextInput
                            v-model="form.phone"
                            type="tel"
                            placeholder="e.g. 0977 000 000"
                        />
                    </FormField>

                    <div class="flex justify-end">
                        <AppButton type="submit" :disabled="form.processing">
                            Save settings
                        </AppButton>
                    </div>
                </form>
            </AppCard>

            <AppCard title="Where things go now" class="mt-4">
                <ul class="space-y-3">
                    <li class="flex items-start gap-3">
                        <Mail class="mt-0.5 size-4 shrink-0 text-brand-700" />
                        <div class="text-sm">
                            <p class="font-medium text-card-foreground">
                                Email
                            </p>
                            <p class="text-muted-foreground">
                                {{
                                    member.email ??
                                    'No login email — ask the treasurer to invite you.'
                                }}
                                <span v-if="effective.includes('mail')">
                                    · in use</span
                                >
                            </p>
                        </div>
                    </li>
                    <li class="flex items-start gap-3">
                        <MessageSquare
                            class="mt-0.5 size-4 shrink-0 text-brand-700"
                        />
                        <div class="text-sm">
                            <p class="font-medium text-card-foreground">SMS</p>
                            <p class="text-muted-foreground">
                                {{ member.phone ?? 'No number on record.' }}
                                <span v-if="effective.includes('sms')">
                                    · in use</span
                                >
                            </p>
                        </div>
                    </li>
                </ul>

                <p
                    v-if="effective.length === 0"
                    class="mt-4 rounded-md bg-muted p-3 text-sm text-muted-foreground"
                >
                    We have no way to reach you at all. Add a mobile number
                    above, or ask the treasurer to link an email login.
                </p>
                <p
                    v-else-if="
                        !effective.includes('sms') &&
                        form.notification_channel !== 'mail'
                    "
                    class="mt-4 rounded-md bg-muted p-3 text-sm text-muted-foreground"
                >
                    You have asked for SMS but we have no number for you, so
                    messages will go to your email until one is saved.
                </p>
            </AppCard>
        </template>
    </MemberLayout>
</template>
