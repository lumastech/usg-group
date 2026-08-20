<script setup lang="ts">
/**
 * Accessible dialog: focus is trapped while open, Escape and the backdrop close it,
 * and the page behind is locked from scrolling.
 */
import { X } from '@lucide/vue';
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

import { cn } from '@/lib/utils';
import ClientOnly from './ClientOnly.vue';

const props = withDefaults(
    defineProps<{
        title?: string;
        description?: string;
        size?: 'sm' | 'md' | 'lg' | 'xl';
        /** Prevents Escape and backdrop dismissal, for dialogs mid-transaction. */
        persistent?: boolean;
        class?: string;
    }>(),
    { size: 'md' },
);

const open = defineModel<boolean>('open', { default: false });

const emit = defineEmits<{ close: [] }>();

const panel = ref<HTMLElement | null>(null);
let previouslyFocused: HTMLElement | null = null;

const sizes = {
    sm: 'max-w-sm',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
} as const;

function close(): void {
    if (props.persistent) {
        return;
    }

    open.value = false;
    emit('close');
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        close();

        return;
    }

    if (event.key !== 'Tab' || !panel.value) {
        return;
    }

    const focusable = panel.value.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
    );

    if (focusable.length === 0) {
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(open, async (isOpen) => {
    if (isOpen) {
        previouslyFocused = document.activeElement as HTMLElement;
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', onKeydown);
        await nextTick();
        panel.value
            ?.querySelector<HTMLElement>('[autofocus], button, input')
            ?.focus();
    } else {
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onKeydown);
        previouslyFocused?.focus();
    }
});

onBeforeUnmount(() => {
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <ClientOnly>
        <Teleport to="body">
            <Transition
                enter-active-class="transition duration-150 ease-out"
                enter-from-class="opacity-0"
                leave-active-class="transition duration-100 ease-in"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="open"
                    class="fixed inset-0 z-50 flex items-end justify-center sm:items-center"
                >
                    <div
                        class="absolute inset-0 bg-brand-950/50 backdrop-blur-[2px]"
                        @click="close"
                    />

                    <Transition
                        appear
                        enter-active-class="transition duration-200 ease-out"
                        enter-from-class="opacity-0 translate-y-4 sm:scale-95 sm:translate-y-0"
                        leave-active-class="transition duration-100 ease-in"
                        leave-to-class="opacity-0 sm:scale-95"
                    >
                        <div
                            v-if="open"
                            ref="panel"
                            role="dialog"
                            aria-modal="true"
                            :aria-label="title"
                            :class="
                                cn(
                                    'relative m-0 w-full rounded-t-2xl bg-card shadow-pop sm:m-4 sm:rounded-2xl',
                                    sizes[size],
                                    $props.class,
                                )
                            "
                        >
                            <header
                                v-if="title || description || $slots.header"
                                class="flex items-start justify-between gap-4 border-b border-border px-5 py-4"
                            >
                                <div class="min-w-0">
                                    <slot name="header">
                                        <h2
                                            class="text-base font-semibold tracking-tight text-card-foreground"
                                        >
                                            {{ title }}
                                        </h2>
                                        <p
                                            v-if="description"
                                            class="mt-1 text-sm text-muted-foreground"
                                        >
                                            {{ description }}
                                        </p>
                                    </slot>
                                </div>
                                <button
                                    v-if="!persistent"
                                    type="button"
                                    class="-mt-1 -mr-1 grid size-8 shrink-0 place-items-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                    aria-label="Close"
                                    @click="close"
                                >
                                    <X class="size-4" />
                                </button>
                            </header>

                            <div class="px-5 py-4">
                                <slot />
                            </div>

                            <footer
                                v-if="$slots.footer"
                                class="flex items-center justify-end gap-2 border-t border-border px-5 py-4"
                            >
                                <slot name="footer" />
                            </footer>
                        </div>
                    </Transition>
                </div>
            </Transition>
        </Teleport>
    </ClientOnly>
</template>
