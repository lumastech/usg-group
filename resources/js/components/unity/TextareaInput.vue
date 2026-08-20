<script setup lang="ts">
/** Multi-line input matching the portal's field styling. Pairs with FormField. */
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        id?: string;
        name?: string;
        rows?: number;
        placeholder?: string;
        disabled?: boolean;
        readonly?: boolean;
        invalid?: boolean;
        class?: string;
    }>(),
    { rows: 3 },
);

const model = defineModel<string | null>({ default: '' });
</script>

<template>
    <textarea
        :id="id"
        v-model="model"
        :name="name"
        :rows="rows"
        :placeholder="placeholder"
        :disabled="disabled"
        :readonly="readonly"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'w-full rounded-lg border bg-card px-3 py-2 text-sm text-foreground transition-colors outline-none',
                'placeholder:text-muted-foreground/60 focus:ring-2 focus:ring-ring focus:ring-offset-1 focus:ring-offset-background',
                'disabled:cursor-not-allowed disabled:opacity-60',
                invalid
                    ? 'border-destructive'
                    : 'border-input focus:border-brand-400',
                $props.class,
            )
        "
    />
</template>
