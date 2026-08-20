<script setup lang="ts">
/** Checkbox with its own label, for the portal's single-flag fields. */
import { useId } from 'vue';

import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        label: string;
        hint?: string;
        id?: string;
        name?: string;
        disabled?: boolean;
        class?: string;
    }>(),
    {},
);

const model = defineModel<boolean>({ default: false });
const generated = useId();
</script>

<template>
    <div :class="cn('flex items-start gap-2.5', $props.class)">
        <input
            :id="id ?? generated"
            v-model="model"
            type="checkbox"
            :name="name"
            :disabled="disabled"
            class="mt-0.5 size-4 shrink-0 rounded border-input text-brand-700 accent-brand-700 focus:ring-2 focus:ring-ring disabled:opacity-60"
        />
        <label
            :for="id ?? generated"
            class="text-sm text-foreground select-none"
        >
            {{ label }}
            <span
                v-if="hint"
                class="mt-0.5 block text-xs text-muted-foreground"
                >{{ hint }}</span
            >
        </label>
    </div>
</template>
