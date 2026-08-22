<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();

            /* Null for money that belongs to the group rather than to one member. */
            $table->foreignId('member_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_month_id')->nullable()->constrained()->nullOnDelete();

            $table->string('direction', 16);
            $table->string('purpose', 40);
            $table->string('channel', 24);

            /*
             * What the member is asked for, or what the group sends. The fee is what the
             * provider charged and is recorded beside it rather than inside it — money
             * in is quoted to the member gross of their own fee, money out is never
             * reduced by ours.
             */
            $table->bigInteger('amount_ngwee');
            $table->bigInteger('fee_ngwee')->nullable();
            $table->string('fee_bearer', 16)->nullable();

            /* Ours, unique, and never reused: the provider rejects a duplicate. */
            $table->string('reference', 64)->unique();

            /* Theirs: the transaction uuid and the human-facing reference. */
            $table->string('provider_id', 64)->nullable();
            $table->string('provider_reference', 64)->nullable();

            $table->string('status', 32);
            $table->text('status_reason')->nullable();

            /* What the money is for: a Loan, a Payout, a claim, a TradingEntry. */
            $table->nullableMorphs('payable', 'payment_intents_payable_index');

            $table->foreignId('payout_destination_id')
                ->nullable()
                ->constrained('payout_destinations', indexName: 'payment_intents_destination_foreign')
                ->nullOnDelete();

            /*
             * completed_at is the PROVIDER's timestamp, not ours. A repayment made at
             * 23:50 on the 7th whose webhook we handle on the 8th must be allocated on
             * the 7th, or the member is charged a late penalty for our queue depth.
             */
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('posted_at')->nullable();

            /*
             * The ledger row this payment produced. Unique, so a webhook and a poll
             * racing each other cannot post the same money twice.
             */
            /* Hand-named: the derived name blows MySQL's 64-character limit. */
            $table->nullableMorphs('posted_transaction', 'payment_intents_posted_txn_index');

            $table->foreignId('requested_by_member_id')
                ->nullable()
                ->constrained('members', indexName: 'payment_intents_requested_by_foreign')
                ->nullOnDelete();
            $table->foreignId('approved_by_member_id')
                ->nullable()
                ->constrained('members', indexName: 'payment_intents_approved_by_foreign')
                ->nullOnDelete();
            $table->foreignId('second_approver_member_id')
                ->nullable()
                ->constrained('members', indexName: 'payment_intents_second_approver_foreign')
                ->nullOnDelete();

            /* What the retry that replaced this one is, if a member tried again. */
            $table->foreignId('retry_of_payment_intent_id')
                ->nullable()
                ->constrained('payment_intents', indexName: 'payment_intents_retry_of_foreign')
                ->nullOnDelete();
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->timestamp('last_polled_at')->nullable();
            $table->unsignedSmallInteger('poll_attempts')->default(0);

            /* The provider's response as received, for the audit trail. */
            $table->json('payload')->nullable();

            $table->timestamps();

            $table->index(['cycle_id', 'status']);
            $table->index(['direction', 'status']);
            $table->index(['member_id', 'purpose']);
            $table->index(['status', 'last_polled_at']);
            $table->index('provider_id');
        });

        /*
         * One ledger row can only ever have been produced by one payment. Added
         * separately because nullableMorphs() indexes but does not constrain, and this
         * is the guarantee the whole idempotency story rests on.
         */
        Schema::table('payment_intents', function (Blueprint $table): void {
            $table->unique(
                ['posted_transaction_type', 'posted_transaction_id'],
                'payment_intents_posted_transaction_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
