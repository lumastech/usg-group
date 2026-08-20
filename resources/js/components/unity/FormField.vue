<script setup lang="ts">
/**
 * Label, help text and server-side validation error for one field.
 *
 * Errors always come from the backend response — the portal never decides on the
 * client that a value is invalid, so what the user sees matches what was rejected.
 */
import { computed, useId } from 'vue';

import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        label?: string;
        /** The server-side error for this field, e.g. form.errors.amount_ngwee. */
        error?: string;
        hint?: string;
        required?: boolean;
        /** Pass to bind the label to an existing control id. */
        for?: string;
        class?: string;
    }>(),
    {},
);

const generated = useId();
const fieldId = computed(() => props.for ?? generated);
const errorId = computed(() => `${fieldId.value}-error`);
const hintId = computed(() => `${fieldId.value}-hint`);

const describedBy = computed(() => {
    const ids = [
        props.error ? errorId.value : null,
        props.hint ? hintId.value : null,
    ].filter(Boolean);

    return ids.length ? ids.join(' ') : undefined;
});
</script>

<template>
    <div :class="cn('space-y-1.5', $props.class)">
        <label
            v-if="label"
            :for="fieldId"
            class="block text-sm font-medium text-foreground"
        >
            {{ label }}
            <span v-if="required" class="text-destructive" aria-hidden="true"
                >*</span
            >
        </label>

        <slot :id="fieldId" :invalid="!!error" :described-by="describedBy" />

        <p
            v-if="hint && !error"
            :id="hintId"
            class="text-xs text-muted-foreground"
        >
            {{ hint }}
        </p>
        <p
            v-if="error"
            :id="errorId"
            class="text-xs font-medium text-destructive"
            role="alert"
        >
            {{ error }}
        </p>
    </div>
</template>
