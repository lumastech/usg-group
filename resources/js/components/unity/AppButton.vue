<script setup lang="ts">
/**
 * The portal's button. Variants map to intent, not colour, so a destructive action
 * looks the same everywhere without each screen picking its own red.
 */
import { computed } from 'vue';

import { cn } from '@/lib/utils';

type Variant =
    'primary' | 'secondary' | 'ghost' | 'outline' | 'destructive' | 'gold';
type Size = 'sm' | 'md' | 'lg' | 'icon';

const props = withDefaults(
    defineProps<{
        variant?: Variant;
        size?: Size;
        type?: 'button' | 'submit' | 'reset';
        disabled?: boolean;
        loading?: boolean;
        block?: boolean;
        class?: string;
    }>(),
    { variant: 'primary', size: 'md', type: 'button' },
);

const variants: Record<Variant, string> = {
    primary:
        'bg-brand-700 text-white hover:bg-brand-800 active:bg-brand-900 shadow-sm',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-accent',
    ghost: 'text-foreground hover:bg-accent hover:text-accent-foreground',
    outline:
        'border border-input bg-card text-foreground hover:bg-accent hover:border-brand-300',
    destructive:
        'bg-destructive text-white hover:brightness-95 active:brightness-90 shadow-sm',
    gold: 'bg-gold-400 text-brand-950 hover:bg-gold-500 active:bg-gold-600 shadow-sm',
};

const sizes: Record<Size, string> = {
    sm: 'h-8 px-3 text-xs gap-1.5 rounded-md',
    md: 'h-10 px-4 text-sm gap-2 rounded-lg',
    lg: 'h-12 px-6 text-base gap-2.5 rounded-lg',
    icon: 'size-10 rounded-lg',
};

const isDisabled = computed(() => props.disabled || props.loading);

const classes = computed(() =>
    cn(
        'relative inline-flex items-center justify-center font-medium whitespace-nowrap',
        'transition-[background-color,border-color,color,box-shadow,filter] duration-150',
        'focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none',
        'disabled:pointer-events-none disabled:opacity-50',
        variants[props.variant],
        sizes[props.size],
        props.block && 'w-full',
        props.class,
    ),
);
</script>

<template>
    <button
        :type="type"
        :disabled="isDisabled"
        :class="classes"
        :aria-busy="loading || undefined"
    >
        <span
            v-if="loading"
            class="size-4 shrink-0 animate-spin rounded-full border-2 border-current border-t-transparent"
            aria-hidden="true"
        />
        <slot v-if="!loading" name="icon" />
        <slot />
    </button>
</template>
