<script setup lang="ts">
/** Member landing page: one member's own position in the cycle. */
import { PiggyBank, TrendingUp } from '@lucide/vue';
import { AppCard, EmptyState, StatCard } from '@/components/unity';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';

defineProps<{
    member: {
        id: number;
        full_name: string;
        member_number: number;
        status: string;
    } | null;
    cycleName: string | null;
}>();
</script>

<template>
    <MemberLayout
        title="My savings"
        :heading="member?.full_name"
        :description="cycleName ?? undefined"
    >
        <div v-if="member" class="grid gap-4 sm:grid-cols-2">
            <StatCard
                label="My savings"
                :ngwee="0"
                :icon="PiggyBank"
                accent="brand"
            />
            <StatCard
                label="Interest earned"
                :ngwee="0"
                :icon="TrendingUp"
                accent="gold"
            />
        </div>

        <AppCard v-else>
            <EmptyState
                title="No member record"
                description="Your login is not linked to a member in the current cycle. Ask the treasurer to link it."
            />
        </AppCard>
    </MemberLayout>
</template>
