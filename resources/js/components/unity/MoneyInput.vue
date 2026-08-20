<script setup lang="ts">
/**
 * Kwacha input that emits integer ngwee.
 *
 * The user types Kwacha ("1,500.50"); the model is always ngwee (150050). Where a
 * form requires fixed increments — savings must move in K500 steps — pass
 * `:step="50000"` and the value snaps to the nearest valid step on blur, so a
 * member cannot submit K537 by hand.
 */
import { Minus, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

import { formatMoney, isOnStep, parseMoney, roundToStep } from '@/lib/money';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        /** Increment in ngwee, e.g. 50_000 for K500. Zero disables stepping. */
        step?: number;
        min?: number;
        max?: number;
        disabled?: boolean;
        readonly?: boolean;
        placeholder?: string;
        id?: string;
        name?: string;
        invalid?: boolean;
        /** Show the -/+ stepper buttons. */
        steppers?: boolean;
        class?: string;
    }>(),
    { step: 0, placeholder: '0.00', steppers: true },
);

const model = defineModel<number | null>({ default: null });

const emit = defineEmits<{ blur: []; change: [value: number | null] }>();

const focused = ref(false);
const draft = ref('');

/** While focused the raw text is authoritative; otherwise show formatted Kwacha. */
const display = computed<string>(() => {
    if (focused.value) {
        return draft.value;
    }

    return model.value === null
        ? ''
        : formatMoney(model.value, { symbol: false });
});

watch(
    () => model.value,
    (value) => {
        if (!focused.value) {
            draft.value = value === null ? '' : String(value / 100);
        }
    },
    { immediate: true },
);

function onInput(event: Event): void {
    draft.value = (event.target as HTMLInputElement).value;
    commit(parseMoney(draft.value), { snap: false });
}

function onFocus(): void {
    draft.value = model.value === null ? '' : String(model.value / 100);
    focused.value = true;
}

/** Snapping happens on blur, not on every keystroke, so typing stays usable. */
function onBlur(): void {
    focused.value = false;
    commit(parseMoney(draft.value), { snap: true });
    emit('blur');
}

function commit(value: number | null, { snap }: { snap: boolean }): void {
    let next = value;

    if (
        next !== null &&
        snap &&
        props.step > 0 &&
        !isOnStep(next, props.step)
    ) {
        next = roundToStep(next, props.step);
    }

    if (next !== null) {
        if (props.min !== undefined && next < props.min) {
            next = props.min;
        }

        if (props.max !== undefined && next > props.max) {
            next = props.max;
        }
    }

    if (next !== model.value) {
        model.value = next;
        emit('change', next);
    }
}

function nudge(direction: 1 | -1): void {
    const step = props.step > 0 ? props.step : 10_000;
    const base = model.value ?? props.min ?? 0;

    commit(roundToStep(base + direction * step, step), { snap: true });
}

const canDecrease = computed(
    () =>
        !props.disabled &&
        !props.readonly &&
        (props.min === undefined || (model.value ?? 0) > props.min),
);
const canIncrease = computed(
    () =>
        !props.disabled &&
        !props.readonly &&
        (props.max === undefined || (model.value ?? 0) < props.max),
);

/** Surfaced under the field so the K500 rule is visible before submitting. */
const stepHint = computed(() =>
    props.step > 0 ? `In steps of ${formatMoney(props.step)}` : null,
);
</script>

<template>
    <div class="space-y-1.5">
        <div
            :class="
                cn(
                    'flex items-stretch overflow-hidden rounded-lg border bg-card transition-colors',
                    'focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1 focus-within:ring-offset-background',
                    invalid
                        ? 'border-destructive'
                        : 'border-input focus-within:border-brand-400',
                    disabled && 'pointer-events-none opacity-60',
                    $props.class,
                )
            "
        >
            <button
                v-if="steppers"
                type="button"
                tabindex="-1"
                :disabled="!canDecrease"
                class="grid w-10 shrink-0 place-items-center border-r border-input text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-40"
                aria-label="Decrease amount"
                @click="nudge(-1)"
            >
                <Minus class="size-4" />
            </button>

            <span
                class="grid place-items-center pl-3 text-sm font-medium text-muted-foreground select-none"
                >K</span
            >

            <input
                :id="id"
                :name="name"
                type="text"
                inputmode="decimal"
                autocomplete="off"
                :value="display"
                :placeholder="placeholder"
                :disabled="disabled"
                :readonly="readonly"
                :aria-invalid="invalid || undefined"
                class="tabular w-full bg-transparent px-2 py-2.5 text-right text-sm font-medium text-foreground outline-none placeholder:text-muted-foreground/60"
                @input="onInput"
                @focus="onFocus"
                @blur="onBlur"
            />

            <button
                v-if="steppers"
                type="button"
                tabindex="-1"
                :disabled="!canIncrease"
                class="grid w-10 shrink-0 place-items-center border-l border-input text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-40"
                aria-label="Increase amount"
                @click="nudge(1)"
            >
                <Plus class="size-4" />
            </button>
        </div>

        <p v-if="stepHint" class="text-xs text-muted-foreground">
            {{ stepHint }}
        </p>
    </div>
</template>
