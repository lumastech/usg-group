<script setup lang="ts">
/**
 * Registering a member.
 *
 * The whole page becomes a locked explanation once the cycle's registration window
 * has passed — the constitution allows no override, so there is no form to show.
 */
import { useForm } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { LockKeyhole } from '@lucide/vue';

import MemberForm from '@/components/members/MemberForm.vue';
import { AppButton, AppCard, EmptyState } from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import { NextOfKinRelationship } from '@/types/enums';
import type {
    EnumOption,
    MemberFormData,
    RegistrationState,
} from '@/types/members';

const props = defineProps<{
    registration: RegistrationState;
    relationships: EnumOption[];
    canCreate: boolean;
}>();

const form = useForm<MemberFormData>({
    full_name: '',
    nrc_number: '',
    phone: '',
    physical_address: '',
    is_diaspora: false,
    joined_on: new Date().toISOString().slice(0, 10),
    joining_fee_ngwee: props.registration.standard_fee_ngwee ?? null,
    joining_fee_paid: true,
    next_of_kin: [
        {
            name: '',
            phone: '',
            relationship: NextOfKinRelationship.Spouse,
            relationship_label: '',
        },
    ],
});

function submit(): void {
    form.post('/app/members', { preserveScroll: true });
}
</script>

<template>
    <AdminLayout
        title="Register member"
        heading="Register member"
        description="Add a member to the current cycle"
    >
        <AppCard v-if="!registration.open || !canCreate">
            <EmptyState
                :icon="LockKeyhole"
                title="Registration is closed"
                :description="`Membership closed after month ${registration.closes_after_month} of the cycle, and the constitution allows no exception. The register can still be corrected from a member's profile.`"
            >
                <template #action>
                    <Link href="/app/members"
                        ><AppButton variant="outline"
                            >Back to members</AppButton
                        ></Link
                    >
                </template>
            </EmptyState>
        </AppCard>

        <form v-else @submit.prevent="submit">
            <AppCard title="Member details">
                <MemberForm
                    v-model="form"
                    mode="create"
                    :errors="form.errors"
                    :relationships="relationships"
                    :registration="registration"
                />
            </AppCard>

            <div class="mt-4 flex items-center justify-end gap-2">
                <Link href="/app/members"
                    ><AppButton variant="ghost" type="button"
                        >Cancel</AppButton
                    ></Link
                >
                <AppButton type="submit" :loading="form.processing"
                    >Register member</AppButton
                >
            </div>
        </form>
    </AdminLayout>
</template>
