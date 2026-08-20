<script setup lang="ts">
/** Progress header for multi-step wizards (registration, share-out, loan capture). */
import { Check } from '@lucide/vue';
import { computed } from 'vue';

import { cn } from '@/lib/utils';

export type Step = {
    key: string;
    label: string;
    description?: string;
};

const props = defineProps<{
    steps: Step[];
    /** Zero-based index of the step in progress. */
    current: number;
    class?: string;
}>();

function state(index: number): 'complete' | 'current' | 'upcoming' {
    if (index < props.current) {
        return 'complete';
    }

    return index === props.current ? 'current' : 'upcoming';
}

const percent = computed(() =>
    props.steps.length < 2
        ? 0
        : (Math.min(props.current, props.steps.length - 1) /
              (props.steps.length - 1)) *
          100,
);
</script>

<template>
    <nav :class="cn('w-full', $props.class)" aria-label="Progress">
        <ol class="relative flex items-start justify-between gap-2">
            <div
                class="absolute top-4 right-0 left-0 -z-10 mx-8 h-0.5 bg-border"
                aria-hidden="true"
            >
                <div
                    class="h-full bg-brand-600 transition-all duration-300"
                    :style="{ width: `${percent}%` }"
                />
            </div>

            <li
                v-for="(step, index) in steps"
                :key="step.key"
                class="flex min-w-0 flex-1 flex-col items-center text-center"
                :aria-current="state(index) === 'current' ? 'step' : undefined"
            >
                <span
                    :class="
                        cn(
                            'tabular grid size-8 shrink-0 place-items-center rounded-full border-2 text-xs font-semibold transition-colors',
                            state(index) === 'complete' &&
                                'border-brand-600 bg-brand-600 text-white',
                            state(index) === 'current' &&
                                'border-brand-600 bg-card text-brand-700 ring-4 ring-brand-100 dark:ring-brand-400/15',
                            state(index) === 'upcoming' &&
                                'border-border bg-card text-muted-foreground',
                        )
                    "
                >
                    <Check v-if="state(index) === 'complete'" class="size-4" />
                    <template v-else>{{ index + 1 }}</template>
                </span>

                <span
                    :class="
                        cn(
                            'mt-2 truncate text-xs font-medium',
                            state(index) === 'upcoming'
                                ? 'text-muted-foreground'
                                : 'text-foreground',
                        )
                    "
                >
                    {{ step.label }}
                </span>
                <span
                    v-if="step.description"
                    class="mt-0.5 hidden text-[0.6875rem] text-muted-foreground sm:block"
                >
                    {{ step.description }}
                </span>
            </li>
        </ol>
    </nav>
</template>
