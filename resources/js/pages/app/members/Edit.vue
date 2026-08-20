<script setup lang="ts">
/**
 * Correcting a registered member's details.
 *
 * Join date and joining fee are absent by design: they fix which tier the member
 * joined under, and a correction to those belongs in a reversing entry, not here.
 */
import { Link, useForm } from '@inertiajs/vue3';

import MemberForm from '@/components/members/MemberForm.vue';
import { AppButton, AppCard } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { NextOfKinRelationship } from '@/types/enums';
import type {
    EnumOption,
    Member,
    MemberFormData,
    RegistrationState,
} from '@/types/members';

const props = defineProps<{
    member: Member;
    relationships: EnumOption[];
    registration: RegistrationState;
}>();

const member = props.member;

const form = useForm<MemberFormData>({
    full_name: member.full_name,
    nrc_number: member.nrc_number ?? '',
    phone: member.phone ?? '',
    physical_address: member.physical_address ?? '',
    is_diaspora: member.is_diaspora,
    joined_on: member.joined_on,
    joining_fee_ngwee: member.joining_fee_ngwee,
    joining_fee_paid: member.joining_fee_paid,
    next_of_kin: (member.next_of_kin ?? []).map((kin) => ({
        name: kin.name,
        phone: kin.phone ?? '',
        relationship: kin.relationship ?? NextOfKinRelationship.Other,
        relationship_label: kin.relationship_label ?? '',
    })),
});

function submit(): void {
    form.put(`/app/members/${member.id}`, { preserveScroll: true });
}
</script>

<template>
    <AdminLayout
        title="Edit member"
        :heading="member.full_name"
        :description="`Member number ${member.member_number}`"
    >
        <form @submit.prevent="submit">
            <AppCard title="Member details">
                <MemberForm
                    v-model="form"
                    mode="edit"
                    :errors="form.errors"
                    :relationships="relationships"
                    :registration="registration"
                />
            </AppCard>

            <div class="mt-4 flex items-center justify-end gap-2">
                <Link :href="`/app/members/${member.id}`">
                    <AppButton variant="ghost" type="button">Cancel</AppButton>
                </Link>
                <AppButton type="submit" :loading="form.processing"
                    >Save changes</AppButton
                >
            </div>
        </form>
    </AdminLayout>
</template>
