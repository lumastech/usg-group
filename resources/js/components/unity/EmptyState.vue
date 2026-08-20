<script setup lang="ts">
/** Shown wherever a list, table or matrix has nothing to display yet. */
import { Inbox } from '@lucide/vue';

import type { Component } from 'vue';
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: Component;
        class?: string;
    }>(),
    {},
);
</script>

<template>
    <div
        :class="
            cn(
                'flex flex-col items-center justify-center px-6 py-12 text-center',
                $props.class,
            )
        "
    >
        <span
            class="grid size-12 place-items-center rounded-full bg-muted text-muted-foreground"
        >
            <component :is="icon ?? Inbox" class="size-6" aria-hidden="true" />
        </span>
        <h3 class="mt-4 text-sm font-semibold text-foreground">{{ title }}</h3>
        <p
            v-if="description"
            class="mt-1 max-w-sm text-sm text-muted-foreground"
        >
            {{ description }}
        </p>
        <div v-if="$slots.action" class="mt-4">
            <slot name="action" />
        </div>
    </div>
</template>
