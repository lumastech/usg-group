<script setup lang="ts">
/** Member landing page: one member's own position in the cycle. */
import { Link } from '@inertiajs/vue3';
import { ClipboardList, PiggyBank, TrendingUp } from '@lucide/vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    StatCard,
    WindowCountdown,
} from '@/components/unity';
import MemberLayout from '@/layouts/unity/MemberLayout.vue';
import type { DeclarationMonth } from '@/types/declarations';

defineProps<{
    member: {
        id: number;
        full_name: string;
        member_number: number;
        status: string;
    } | null;
    cycleName: string | null;
    monthWindow?: (DeclarationMonth & { has_declared: boolean }) | null;
}>();
</script>

<template>
    <MemberLayout
        title="My savings"
        :heading="member?.full_name"
        :description="cycleName ?? undefined"
    >
        <!-- The one thing a member may owe the group today, before anything else. -->
        <div v-if="member && monthWindow" class="mb-5 space-y-3">
            <WindowCountdown
                :window="monthWindow.window"
                :seconds-remaining="monthWindow.seconds_remaining"
            />

            <AppCard
                v-if="
                    monthWindow.declarations_open && !monthWindow.has_declared
                "
                :title="`Declare for ${monthWindow.label}`"
                description="Tell the group what you will bring to the table this month."
            >
                <Link href="/my/declarations" class="block">
                    <AppButton block>
                        <template #icon
                            ><ClipboardList class="size-4"
                        /></template>
                        Make my declaration
                    </AppButton>
                </Link>
            </AppCard>

            <AppCard
                v-else-if="monthWindow.has_declared"
                :title="`Declared for ${monthWindow.label}`"
                :description="`Bring it to the table by ${monthWindow.trading_concludes_on}.`"
            >
                <Link href="/my/declarations" class="block">
                    <AppButton variant="outline" block>
                        View my declaration
                    </AppButton>
                </Link>
            </AppCard>
        </div>

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
