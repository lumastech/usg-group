<script setup lang="ts">
/**
 * The gate between a running cycle and share-out.
 *
 * Each check is green or red with a drill-down to whatever is still blocking it. The
 * transition is refused by the server until every line is clear — or until two
 * committee members override it in writing, which is why the override sits behind the
 * dual-approval dialog rather than behind a checkbox.
 *
 * What this page shows is advice. The checklist is re-run inside the domain at the
 * moment the transition is posted, because a repayment can land in the minutes between
 * looking at it and signing for it.
 */
import { Link, router, useForm } from '@inertiajs/vue3';
import { CircleCheck, CircleX, LockKeyhole, ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    ClientOnly,
    ConfirmDialog,
    EmptyState,
    MoneyText,
    StatCard,
    TextareaInput,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { Preflight, ShareOutCycle } from '@/types/shareout';

const props = defineProps<{
    cycle: ShareOutCycle | null;
    preflight: Preflight | null;
    abilities: { beginClosing: boolean; openShareOut: boolean };
}>();

const overrideOpen = ref(false);

const form = useForm({
    override_note: '',
    approver_email: '',
    approver_password: '',
});

const blocking = computed(
    () => props.preflight?.items.filter((item) => !item.passed) ?? [],
);

const needsOverride = computed(() => blocking.value.length > 0);

function beginClosing(): void {
    router.post('/app/shareout/close', {}, { preserveScroll: true });
}

/** A clean checklist posts on its own; a dirty one collects a second signature. */
function openShareOut(payload: {
    approver_email?: string;
    approver_password?: string;
} = {}): void {
    form.transform((data) => ({
        override_note: needsOverride.value ? data.override_note : '',
        approver_email: payload.approver_email ?? '',
        approver_password: payload.approver_password ?? '',
    })).post('/app/shareout/open', {
        preserveScroll: true,
        onSuccess: () => {
            overrideOpen.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Share-out checklist"
        heading="Pre-flight checklist"
        description="What still stands between the cycle and the day the money is divided."
    >
        <AppCard v-if="!cycle || !preflight">
            <EmptyState
                title="No active cycle"
                description="Activate a cycle to work the share-out checklist."
            />
        </AppCard>

        <div v-else class="space-y-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Cycle status"
                    :value="cycle.status_label"
                    :icon="LockKeyhole"
                    :accent="cycle.is_sharing_out ? 'brand' : 'none'"
                />
                <StatCard
                    label="Checks clear"
                    :value="`${preflight.items.length - preflight.blocking_count} of ${preflight.items.length}`"
                    :icon="CircleCheck"
                    :accent="preflight.passed ? 'brand' : 'gold'"
                />
                <StatCard
                    label="Still blocking"
                    :value="preflight.blocking_count"
                    :icon="CircleX"
                    :accent="preflight.blocking_count > 0 ? 'gold' : 'none'"
                    hint="Each must be cleared or overridden"
                />
            </div>

            <div class="space-y-3">
                <AppCard
                    v-for="item in preflight.items"
                    :key="item.key"
                    flush
                    :class="
                        item.passed
                            ? 'border-l-4 border-l-brand-500'
                            : 'border-l-4 border-l-gold-500'
                    "
                >
                    <div class="space-y-3 p-4">
                        <div class="flex items-start gap-3">
                            <component
                                :is="item.passed ? CircleCheck : CircleX"
                                class="mt-0.5 size-5 shrink-0"
                                :class="
                                    item.passed
                                        ? 'text-brand-600 dark:text-brand-400'
                                        : 'text-gold-600 dark:text-gold-400'
                                "
                            />
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ item.label }}</p>
                                <p class="text-sm text-muted-foreground">
                                    {{ item.description }}
                                </p>
                                <p
                                    class="mt-1 text-sm"
                                    :class="
                                        item.passed
                                            ? 'text-muted-foreground'
                                            : 'text-gold-700 dark:text-gold-300'
                                    "
                                >
                                    {{ item.verdict }}
                                </p>
                            </div>
                            <Link
                                :href="item.href"
                                class="shrink-0 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400"
                            >
                                Open
                            </Link>
                        </div>

                        <ul
                            v-if="item.outstanding.length"
                            class="space-y-1 rounded-md bg-muted/40 p-2"
                        >
                            <li
                                v-for="row in item.outstanding.slice(0, 12)"
                                :key="`${item.key}-${row.id}-${row.label}`"
                                class="flex items-center justify-between gap-3 px-2 py-1 text-sm"
                            >
                                <Link
                                    :href="row.href"
                                    class="truncate font-medium hover:underline"
                                >
                                    {{ row.label }}
                                </Link>
                                <span
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ row.detail }}
                                </span>
                                <MoneyText
                                    v-if="row.amount_ngwee !== undefined"
                                    :ngwee="row.amount_ngwee"
                                    class="shrink-0 text-sm"
                                />
                            </li>
                            <li
                                v-if="item.outstanding.length > 12"
                                class="px-2 py-1 text-xs text-muted-foreground"
                            >
                                and {{ item.outstanding.length - 12 }} more…
                            </li>
                        </ul>
                    </div>
                </AppCard>
            </div>

            <AppCard
                title="Move the cycle on"
                description="Lending stops at Closing. Share-out is what lets members be paid."
            >
                <div class="space-y-4">
                    <p
                        v-if="cycle.is_sharing_out"
                        class="text-sm text-muted-foreground"
                    >
                        This cycle is already sharing out. Head to the
                        <Link
                            href="/app/shareout"
                            class="font-medium text-brand-600 hover:underline dark:text-brand-400"
                            >share-out sheet</Link
                        >
                        to settle members.
                    </p>

                    <AppButton
                        v-else-if="abilities.beginClosing"
                        @click="beginClosing"
                    >
                        Close the cycle to new lending
                    </AppButton>

                    <template v-else-if="abilities.openShareOut">
                        <TextareaInput
                            v-if="needsOverride"
                            v-model="form.override_note"
                            label="Override reason"
                            :error="form.errors.override_note"
                            placeholder="Why the committee is opening share-out with checks outstanding"
                            rows="3"
                        />

                        <p
                            v-if="needsOverride"
                            class="text-sm text-gold-700 dark:text-gold-300"
                        >
                            {{ blocking.length }} check(s) are still outstanding.
                            Opening share-out anyway needs a written reason and a
                            second committee member's confirmation.
                        </p>

                        <AppButton
                            :disabled="form.processing"
                            @click="
                                needsOverride
                                    ? (overrideOpen = true)
                                    : openShareOut()
                            "
                        >
                            <ShieldCheck class="size-4" />
                            {{
                                needsOverride
                                    ? 'Override and open share-out'
                                    : 'Open share-out'
                            }}
                        </AppButton>
                    </template>

                    <p v-else class="text-sm text-muted-foreground">
                        Moving the cycle on is the administrator's to do.
                    </p>
                </div>
            </AppCard>
        </div>

        <ClientOnly>
            <ConfirmDialog
                v-model:open="overrideOpen"
                title="Override the checklist"
                variant="dual-approval"
                confirm-label="Open share-out"
                :action-summary="`Open share-out with ${blocking.length} check(s) outstanding`"
                :errors="form.errors"
                :processing="form.processing"
                @confirm="openShareOut"
                @cancel="overrideOpen = false"
            />
        </ClientOnly>
    </AdminLayout>
</template>
