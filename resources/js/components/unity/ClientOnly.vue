<script setup lang="ts">
/**
 * Renders its slot only after mount.
 *
 * Inertia server-side renders in dev, and `<Teleport>` has no server equivalent —
 * the server emits a comment node where the client expects a div, hydration
 * mismatches, and Vue aborts *before binding any event handlers on the page*.
 * The symptom is a page that renders perfectly and responds to nothing.
 *
 * Anything that teleports (Modal, Toast) must sit inside this.
 */
import { onMounted, ref } from 'vue';

const mounted = ref(false);

onMounted(() => {
    mounted.value = true;
});
</script>

<template>
    <slot v-if="mounted" />
</template>
