<script setup lang="ts">
/** Select matching the portal's field styling. Pairs with FormField. */
import { cn } from '@/lib/utils';

export type SelectOption = {
    value: string | number;
    label: string;
    disabled?: boolean;
};

withDefaults(
    defineProps<{
        options: SelectOption[];
        id?: string;
        name?: string;
        /** Shown as a disabled first option when no value is selected. */
        placeholder?: string;
        disabled?: boolean;
        invalid?: boolean;
        class?: string;
    }>(),
    {},
);

const model = defineModel<string | number | null>({ default: null });
</script>

<template>
    <select
        :id="id"
        v-model="model"
        :name="name"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :class="
            cn(
                'h-10 w-full appearance-none rounded-lg border bg-card px-3 text-sm text-foreground transition-colors outline-none',
                'bg-[length:1rem] bg-[right_0.75rem_center] bg-no-repeat pr-9',
                'focus:ring-2 focus:ring-ring focus:ring-offset-1 focus:ring-offset-background',
                'disabled:cursor-not-allowed disabled:opacity-60',
                invalid
                    ? 'border-destructive'
                    : 'border-input focus:border-brand-400',
                $props.class,
            )
        "
        style="
            background-image: url(&quot;data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E&quot;);
        "
    >
        <option v-if="placeholder" :value="null" disabled>
            {{ placeholder }}
        </option>
        <option
            v-for="option in options"
            :key="option.value"
            :value="option.value"
            :disabled="option.disabled"
        >
            {{ option.label }}
        </option>
    </select>
</template>
