<script setup lang="ts">
/**
 * The surface every panel in the portal sits on. Header slots are optional, so it
 * works both as a titled section and as a bare container.
 */
import { cn } from '@/lib/utils';

withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        /** Removes body padding, for cards that hold a flush table. */
        flush?: boolean;
        class?: string;
    }>(),
    {},
);
</script>

<template>
    <section
        :class="
            cn(
                'rounded-xl border border-border bg-card shadow-card',
                $props.class,
            )
        "
    >
        <header
            v-if="title || description || $slots.header || $slots.actions"
            class="flex items-start justify-between gap-4 border-b border-border px-5 py-4"
        >
            <div class="min-w-0">
                <slot name="header">
                    <h2
                        v-if="title"
                        class="text-sm font-semibold tracking-tight text-card-foreground"
                    >
                        {{ title }}
                    </h2>
                    <p
                        v-if="description"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                </slot>
            </div>
            <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div :class="flush ? '' : 'p-5'">
            <slot />
        </div>

        <footer v-if="$slots.footer" class="border-t border-border px-5 py-3">
            <slot name="footer" />
        </footer>
    </section>
</template>
