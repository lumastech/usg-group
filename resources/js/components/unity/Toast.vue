<script setup lang="ts">
/**
 * Flash-message host. Reads Inertia's `flash` prop after every visit, so a
 * controller redirect carrying with('success', …) surfaces without page wiring.
 */
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Info, X } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';

import { cn } from '@/lib/utils';
import ClientOnly from './ClientOnly.vue';

type ToastKind = 'success' | 'error' | 'info' | 'warning';

type ToastItem = {
    id: number;
    kind: ToastKind;
    message: string;
};

const page = usePage();
const toasts = ref<ToastItem[]>([]);
let nextId = 0;

const styles: Record<ToastKind, string> = {
    success:
        'border-brand-200 bg-brand-50 text-brand-900 dark:border-brand-400/30 dark:bg-brand-950 dark:text-brand-100',
    error: 'border-red-200 bg-red-50 text-red-900 dark:border-red-500/30 dark:bg-red-950 dark:text-red-100',
    warning:
        'border-gold-200 bg-gold-50 text-gold-900 dark:border-gold-400/30 dark:bg-gold-950 dark:text-gold-100',
    info: 'border-sky-200 bg-sky-50 text-sky-900 dark:border-sky-500/30 dark:bg-sky-950 dark:text-sky-100',
};

const icons: Record<ToastKind, typeof CircleCheck> = {
    success: CircleCheck,
    error: CircleAlert,
    warning: CircleAlert,
    info: Info,
};

function push(kind: ToastKind, message: string): void {
    const id = nextId++;
    toasts.value.push({ id, kind, message });

    setTimeout(() => dismiss(id), 5000);
}

function dismiss(id: number): void {
    toasts.value = toasts.value.filter((toast) => toast.id !== id);
}

const flash = computed(
    () => (page.props.flash ?? {}) as Partial<Record<ToastKind, string>>,
);

function readFlash(): void {
    (Object.keys(styles) as ToastKind[]).forEach((kind) => {
        const message = flash.value[kind];

        if (message) {
            push(kind, message);
        }
    });
}

onMounted(readFlash);
watch(flash, readFlash);

defineExpose({ push });
</script>

<template>
    <ClientOnly>
        <Teleport to="body">
            <div
                class="pointer-events-none fixed inset-x-0 top-4 z-[60] flex flex-col items-center gap-2 px-4 sm:top-auto sm:right-4 sm:bottom-4 sm:left-auto sm:items-end"
                role="status"
                aria-live="polite"
            >
                <TransitionGroup
                    enter-active-class="transition duration-200 ease-out"
                    enter-from-class="opacity-0 -translate-y-2 sm:translate-y-2 sm:translate-x-2"
                    leave-active-class="transition duration-150 ease-in"
                    leave-to-class="opacity-0 scale-95"
                >
                    <div
                        v-for="toast in toasts"
                        :key="toast.id"
                        :class="
                            cn(
                                'pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border px-4 py-3 shadow-pop',
                                styles[toast.kind],
                            )
                        "
                    >
                        <component
                            :is="icons[toast.kind]"
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <p class="min-w-0 flex-1 text-sm font-medium">
                            {{ toast.message }}
                        </p>
                        <button
                            type="button"
                            class="shrink-0 opacity-60 transition-opacity hover:opacity-100"
                            aria-label="Dismiss"
                            @click="dismiss(toast.id)"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                </TransitionGroup>
            </div>
        </Teleport>
    </ClientOnly>
</template>
