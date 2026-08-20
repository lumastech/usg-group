<script setup lang="ts">
/**
 * A member's own record.
 *
 * Everything is read-only except how to reach them: name, NRC and standing are the
 * committee's to amend. Each edit is written to the activity log, so the register
 * still shows who changed a number and when.
 */
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    FormField,
    StatusBadge,
    TextareaInput,
    TextInput,
} from '@/components/unity';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import { formatMoney } from '@/lib/money';
import type { Member } from '@/types/members';

const props = defineProps<{ member: Member | null }>();

const editing = ref(false);

const form = useForm({
    phone: props.member?.phone ?? '',
    physical_address: props.member?.physical_address ?? '',
});

function submit(): void {
    if (!props.member) {
        return;
    }

    form.put(`/my/profile/${props.member.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}
</script>

<template>
    <MemberLayout
        title="My profile"
        heading="My profile"
        description="Your details on record"
    >
        <AppCard v-if="!member">
            <EmptyState
                title="No member record"
                description="Your login is not linked to a member in the current cycle. Ask the treasurer to link it."
            />
        </AppCard>

        <template v-else>
            <AppCard title="My details">
                <template #actions>
                    <StatusBadge
                        :status="member.status"
                        :label="member.status_label"
                        size="sm"
                    />
                </template>

                <dl class="space-y-4">
                    <div>
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Full name
                        </dt>
                        <dd class="mt-1 text-sm text-card-foreground">
                            {{ member.full_name }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Member number
                        </dt>
                        <dd class="tabular mt-1 text-sm text-card-foreground">
                            {{ member.member_number }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            NRC
                        </dt>
                        <dd class="mt-1 text-sm text-card-foreground">
                            {{ member.nrc_number ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Joining fee
                        </dt>
                        <dd class="tabular mt-1 text-sm text-card-foreground">
                            {{ formatMoney(member.joining_fee_ngwee) }}
                            <span class="text-muted-foreground">
                                ·
                                {{
                                    member.joining_fee_paid
                                        ? 'received'
                                        : 'outstanding'
                                }}
                            </span>
                        </dd>
                    </div>
                </dl>

                <p class="mt-5 text-xs text-muted-foreground">
                    To correct your name, NRC or standing in the group, speak to
                    the treasurer.
                </p>
            </AppCard>

            <AppCard title="How to reach me" class="mt-4">
                <template v-if="!editing" #actions>
                    <AppButton
                        variant="outline"
                        size="sm"
                        @click="editing = true"
                        >Edit</AppButton
                    >
                </template>

                <form v-if="editing" class="space-y-4" @submit.prevent="submit">
                    <FormField label="Phone" :error="form.errors.phone">
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
                        :error="form.errors.physical_address"
                    >
                        <template #default="{ id, invalid }">
                            <TextareaInput
                                :id="id"
                                v-model="form.physical_address"
                                :invalid="invalid"
                            />
                        </template>
                    </FormField>

                    <div class="flex items-center justify-end gap-2">
                        <AppButton
                            variant="ghost"
                            type="button"
                            @click="editing = false"
                            >Cancel</AppButton
                        >
                        <AppButton type="submit" :loading="form.processing"
                            >Save</AppButton
                        >
                    </div>
                </form>

                <dl v-else class="space-y-4">
                    <div>
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Phone
                        </dt>
                        <dd class="mt-1 text-sm text-card-foreground">
                            {{ member.phone ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            Address
                        </dt>
                        <dd class="mt-1 text-sm text-card-foreground">
                            {{ member.physical_address ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </AppCard>

            <AppCard title="My next of kin" class="mt-4">
                <ul v-if="member.next_of_kin?.length" class="space-y-4">
                    <li v-for="kin in member.next_of_kin" :key="kin.id">
                        <p class="text-sm font-medium text-card-foreground">
                            {{ kin.name }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ kin.relationship_display
                            }}<span v-if="kin.phone"> · {{ kin.phone }}</span>
                        </p>
                    </li>
                </ul>
                <EmptyState
                    v-else
                    title="None recorded"
                    description="Ask the treasurer to add the person the group should contact for you."
                />
            </AppCard>
        </template>
    </MemberLayout>
</template>
