<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a ledger row point back at the money that produced it.
 *
 * Nothing else about the ledgers changes: they stay append-only, and a payment that
 * has to be corrected is still corrected with a reversing entry. This column exists so
 * the reconciliation report can join the two sides without guessing from amounts and
 * dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['savings_transactions', 'loan_transactions', 'social_fund_transactions'] as $ledger) {
            Schema::table($ledger, function (Blueprint $table) use ($ledger): void {
                $table->foreignId('payment_intent_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('payment_intents', indexName: str($ledger)->before('_transactions')->toString().'_txn_payment_intent_foreign')
                    ->nullOnDelete();
            });
        }

        Schema::table('payouts', function (Blueprint $table): void {
            /*
             * A payout is executed — signed, frozen, recorded — before the money is
             * sent. paid_at is stamped when the transfer confirms, so a payout with a
             * null paid_at is a settled position still waiting on its transfer.
             */
            $table->timestamp('paid_at')->nullable()->after('executed_at');
            $table->string('paid_method', 24)->nullable()->after('paid_at');
            $table->foreignId('payment_intent_id')
                ->nullable()
                ->after('paid_method')
                ->constrained('payment_intents', indexName: 'payouts_payment_intent_foreign')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['savings_transactions', 'loan_transactions', 'social_fund_transactions'] as $ledger) {
            Schema::table($ledger, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('payment_intent_id');
            });
        }

        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payment_intent_id');
            $table->dropColumn(['paid_at', 'paid_method']);
        });
    }
};
