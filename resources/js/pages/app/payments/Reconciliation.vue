<script setup lang="ts">
/**
 * The daily comparison of the provider's record against the group's own.
 *
 * A clean row is worth as much as a dirty one: "we checked and it agreed" is the
 * answer the group needs at share-out, not the absence of an alarm. Anything appearing
 * on only one side is listed in full — money the provider moved that never reached the
 * ledgers, and money the ledgers believe in that the provider has no record of.
 */
import { router } from '@inertiajs/vue3';
import { CircleCheck, CircleAlert, RefreshCw } from '@lucide/vue';
import { computed } from 'vue';

import {
    AppButton,
    AppCard,
    EmptyState,
    MoneyText,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { ReconciliationRun } from '@/types/payments';

const props = defineProps<{
    runs: ReconciliationRun[];
    can_run: boolean;
}>();

const latest = computed<ReconciliationRun | null>(() => props.runs[0] ?? null);

function runNow(): void {
    router.post('/app/payments/reconciliation', { days: 1 }, { preserveScroll: true });
}

function when(value: string): string {
    return new Date(value).toLocaleString('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <AdminLayout
        title="Reconciliation"
        heading="Reconciliation"
        description="What the provider moved, against what the group's books took."
    >
        <div class="space-y-5">
            <AppCard
                v-if="latest"
                :class="
                    latest.agrees
                        ? 'border-l-4 border-l-brand-500'
                        : 'border-l-4 border-l-gold-500'
                "
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex gap-3">
                        <component
                            :is="latest.agrees ? CircleCheck : CircleAlert"
                            class="mt-0.5 size-6 shrink-0"
                            :class="
                                latest.agrees
                                    ? 'text-brand-600 dark:text-brand-400'
                                    : 'text-gold-600 dark:text-gold-400'
                            "
                        />
                        <div>
                            <p class="font-medium">
                                {{
                                    latest.agrees
                                        ? 'Both sides agree'
                                        : `${latest.unmatched_count} item(s) need a look`
                                }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Checked {{ when(latest.ran_at) }} for
                                {{ latest.for_date }}
                            </p>
                        </div>
                    </div>

                    <AppButton
                        v-if="can_run"
                        variant="secondary"
                        @click="runNow"
                    >
                        <template #icon><RefreshCw class="size-4" /></template>
                        Check again now
                    </AppButton>
                </div>
            </AppCard>

            <AppCard v-else>
                <EmptyState
                    title="Nothing checked yet"
                    description="The comparison runs nightly. You can also run it now."
                >
                    <AppButton v-if="can_run" @click="runNow">
                        Check now
                    </AppButton>
                </EmptyState>
            </AppCard>

            <AppCard
                v-for="run in runs"
                :key="run.id"
                :title="run.for_date"
                :description="`Checked ${when(run.ran_at)}`"
                flush
            >
                <div class="grid gap-4 p-4 sm:grid-cols-4">
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Money in ({{ run.collections_count }})
                        </p>
                        <MoneyText
                            :ngwee="run.collections_ngwee"
                            class="font-medium"
                        />
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Money out ({{ run.transfers_count }})
                        </p>
                        <MoneyText
                            :ngwee="run.transfers_ngwee"
                            class="font-medium"
                        />
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Fees</p>
                        <MoneyText :ngwee="run.fees_ngwee" class="font-medium" />
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            At the provider
                        </p>
                        <MoneyText
                            v-if="run.provider_balance_ngwee !== null"
                            :ngwee="run.provider_balance_ngwee"
                            class="font-medium"
                        />
                        <p v-else class="text-sm text-muted-foreground">
                            Not available
                        </p>
                    </div>
                </div>

                <ul v-if="run.unmatched.length" class="divide-y border-t">
                    <li
                        v-for="(item, index) in run.unmatched"
                        :key="`${run.id}-${index}`"
                        class="flex items-start justify-between gap-3 p-4"
                    >
                        <div class="min-w-0">
                            <p class="text-sm">{{ item.reason }}</p>
                            <p
                                v-if="item.reference"
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ item.reference }}
                            </p>
                        </div>
                        <MoneyText
                            v-if="item.amount_ngwee"
                            :ngwee="item.amount_ngwee"
                            class="shrink-0 text-sm"
                        />
                    </li>
                </ul>
            </AppCard>
        </div>
    </AdminLayout>
</template>
