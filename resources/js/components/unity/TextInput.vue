<script setup lang="ts">
/** Plain text input matching the portal's field styling. Pairs with FormField. */
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        id?: string;
        name?: string;
        type?: string;
        placeholder?: string;
        disabled?: boolean;
        readonly?: boolean;
        invalid?: boolean;
        autocomplete?: string;
        class?: string;
    }>(),
    { type: 'text' },
);

const model = defineModel<string | number | null>({ default: '' });
</script>

<template>
    <input
        :id="id"
        v-model="model"
        :name="name"
        :type="type"
        :placeholder="placeholder"
        :disabled="disabled"
        :readonly="readonly"
        :autocomplete="autocomplete"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'h-10 w-full rounded-lg border bg-card px-3 text-sm text-foreground transition-colors outline-none',
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
