<script setup lang="ts">
/**
 * The next-of-kin rows on the registration form.
 *
 * The relationship is stored as one of five buckets, but the group's sheets say
 * "Sister" or "Aunt", so choosing Other reveals a free-text field and that wording
 * is kept alongside the bucket rather than thrown away.
 */
import { Plus, Trash2 } from '@lucide/vue';

import {
    AppButton,
    FormField,
    SelectInput,
    TextInput,
} from '@/components/unity';
import { NextOfKinRelationship } from '@/types/enums';
import type { EnumOption, NextOfKinDraft } from '@/types/members';

const props = withDefaults(
    defineProps<{
        relationships: EnumOption[];
        /** Server-side errors keyed as next_of_kin.0.name. */
        errors?: Record<string, string>;
        max?: number;
    }>(),
    { max: 5 },
);

const rows = defineModel<NextOfKinDraft[]>({ required: true });

function add(): void {
    if (rows.value.length >= props.max) {
        return;
    }

    rows.value.push({
        name: '',
        phone: '',
        relationship: NextOfKinRelationship.Spouse,
        relationship_label: '',
    });
}

function remove(index: number): void {
    rows.value.splice(index, 1);
}

function error(index: number, field: string): string | undefined {
    return props.errors?.[`next_of_kin.${index}.${field}`];
}
</script>

<template>
    <div class="space-y-4">
        <div
            v-for="(row, index) in rows"
            :key="index"
            class="rounded-xl border border-border bg-muted/30 p-4"
        >
            <div class="mb-3 flex items-center justify-between">
                <p
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    Next of kin {{ index + 1 }}
                </p>
                <AppButton
                    variant="ghost"
                    size="sm"
                    aria-label="Remove"
                    @click="remove(index)"
                >
                    <template #icon><Trash2 class="size-4" /></template>
                </AppButton>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <FormField
                    label="Full name"
                    :error="error(index, 'name')"
                    required
                >
                    <template #default="{ id, invalid }">
                        <TextInput
                            :id="id"
                            v-model="row.name"
                            :invalid="invalid"
                        />
                    </template>
                </FormField>

                <FormField label="Phone" :error="error(index, 'phone')">
                    <template #default="{ id, invalid }">
                        <TextInput
                            :id="id"
                            v-model="row.phone"
                            :invalid="invalid"
                            placeholder="09…"
                        />
                    </template>
                </FormField>

                <FormField
                    label="Relationship"
                    :error="error(index, 'relationship')"
                    required
                >
                    <template #default="{ id, invalid }">
                        <SelectInput
                            :id="id"
                            v-model="row.relationship"
                            :options="relationships"
                            :invalid="invalid"
                        />
                    </template>
                </FormField>

                <FormField
                    v-if="row.relationship === NextOfKinRelationship.Other"
                    label="How are they related?"
                    hint="Recorded exactly as written, e.g. Aunt."
                    :error="error(index, 'relationship_label')"
                >
                    <template #default="{ id, invalid }">
                        <TextInput
                            :id="id"
                            v-model="row.relationship_label"
                            :invalid="invalid"
                        />
                    </template>
                </FormField>
            </div>
        </div>

        <AppButton
            v-if="rows.length < max"
            variant="outline"
            size="sm"
            @click="add"
        >
            <template #icon><Plus class="size-4" /></template>
            Add next of kin
        </AppButton>
    </div>
</template>
