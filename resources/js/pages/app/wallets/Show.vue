<script setup lang="ts">
/**
 * One member's wallet, and everything that has moved through it.
 *
 * Append-only, like every other ledger here: a correction is a reversing entry sitting
 * beside the entry it undoes, never an edit. What the member sees on their own screen
 * is this same list.
 */
import { ArrowDownLeft, ArrowUpRight } from '@lucide/vue';

import {
    AppCard,
    EmptyState,
    MoneyText,
    StatCard,
    StatusBadge,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { Wallet, WalletEntry } from '@/types/wallets';

defineProps<{
    wallet: Wallet;
    statement: WalletEntry[];
    abilities: { recordCash: boolean };
}>();

function when(value: string | null): string {
    return value
        ? new Date(value).toLocaleDateString('en-GB', { dateStyle: 'medium' })
        : '';
}
</script>

<template>
    <AdminLayout
        title="Wallet"
        :heading="wallet.member_name ?? 'Wallet'"
        description="Money standing between this member and the group."
    >
        <div class="space-y-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <StatCard
                    label="Balance"
                    :ngwee="wallet.balance_ngwee"
                    accent="gold"
                    hint="Not savings. This is money the group owes on demand."
                />
                <AppCard title="Wallet">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Status</dt>
                            <dd>
                                <StatusBadge
                                    :status="wallet.status"
                                    :label="wallet.status_label"
                                    size="sm"
                                />
                            </dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-muted-foreground">Opened</dt>
                            <dd>{{ when(wallet.opened_at) }}</dd>
                        </div>
                        <div
                            v-if="wallet.closed_at"
                            class="flex justify-between gap-4"
                        >
                            <dt class="text-muted-foreground">Closed</dt>
                            <dd>{{ when(wallet.closed_at) }}</dd>
                        </div>
                    </dl>
                </AppCard>
            </div>

            <AppCard title="Statement" flush>
                <EmptyState
                    v-if="statement.length === 0"
                    title="Nothing has moved"
                    description="This wallet has been opened but never used."
                />

                <ul v-else class="divide-y">
                    <li
                        v-for="entry in statement"
                        :key="entry.id"
                        class="flex items-center justify-between gap-3 px-5 py-3"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <component
                                :is="
                                    entry.is_credit
                                        ? ArrowDownLeft
                                        : ArrowUpRight
                                "
                                class="size-4 shrink-0"
                                :class="
                                    entry.is_credit
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-muted-foreground'
                                "
                            />
                            <div class="min-w-0">
                                <p class="text-sm font-medium">
                                    {{ entry.type_label }}
                                    <span
                                        v-if="entry.reverses_id"
                                        class="text-muted-foreground"
                                    >
                                        · undoes #{{ entry.reverses_id }}</span
                                    >
                                </p>
                                <p
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ entry.note ?? entry.counterparty ?? '' }}
                                    <span v-if="entry.payment_reference">
                                        · {{ entry.payment_reference }}</span
                                    >
                                    <span v-if="entry.occurred_on">
                                        · {{ when(entry.occurred_on) }}</span
                                    >
                                </p>
                            </div>
                        </div>

                        <MoneyText
                            :ngwee="entry.amount_ngwee"
                            class="shrink-0 text-sm font-medium tabular-nums"
                            :class="
                                entry.is_credit
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : ''
                            "
                        />
                    </li>
                </ul>
            </AppCard>
        </div>
    </AdminLayout>
</template>
