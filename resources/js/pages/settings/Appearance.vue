<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import { AppCard } from '@/components/unity';
</script>

<template>
    <Head title="Appearance settings" />

    <h1 class="sr-only">Appearance settings</h1>

    <AppCard
        title="Appearance"
        description="How the portal looks on this device"
    >
        <AppearanceTabs />
    </AppCard>
</template>
