<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wallet layer: a ledger of member balances between the group and the provider.
 *
 * See docs/WALLET-PLAN.md. A wallet holds only money that is not yet committed to a
 * ledger — savings are locked until share-out and a wallet balance is not, so if the
 * two were the same balance the group would be running a deposit business it never
 * agreed to run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();

            /* Null for the group's own wallet — the other side of every member's. */
            $table->foreignId('member_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('kind', 16);
            $table->string('status', 16);

            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            /*
             * One member wallet per member per cycle, and — because member_id is null
             * for the group's — MySQL's treatment of NULLs as distinct means the single
             * group wallet per cycle is an application rule, enforced in WalletRegistry.
             */
            $table->unique(['cycle_id', 'member_id'], 'wallets_cycle_member_unique');
            $table->index(['cycle_id', 'kind']);
        });

        Schema::create('wallet_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();

            $table->foreignId('from_wallet_id')
                ->constrained('wallets', indexName: 'wallet_transfers_from_foreign')
                ->cascadeOnDelete();
            $table->foreignId('to_wallet_id')
                ->constrained('wallets', indexName: 'wallet_transfers_to_foreign')
                ->cascadeOnDelete();

            /* Positive: the direction is carried by the two wallet columns. */
            $table->bigInteger('amount_ngwee');

            $table->string('purpose', 40);

            /* What the money is for: a Declaration, a Loan, a Payout, a claim. */
            $table->nullableMorphs('payable', 'wallet_transfers_payable_index');

            $table->foreignId('approved_by_member_id')
                ->nullable()
                ->constrained('members', indexName: 'wallet_transfers_approved_by_foreign')
                ->nullOnDelete();
            $table->foreignId('second_approver_member_id')
                ->nullable()
                ->constrained('members', indexName: 'wallet_transfers_second_approver_foreign')
                ->nullOnDelete();
            $table->foreignId('created_by_member_id')
                ->nullable()
                ->constrained('members', indexName: 'wallet_transfers_created_by_foreign')
                ->nullOnDelete();

            $table->timestamp('occurred_at');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['cycle_id', 'purpose']);
        });

        Schema::create('wallet_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();

            /*
             * SIGNED: credits positive, debits negative. The balance is a plain SUM of
             * this column and nothing caches it, exactly as the social fund's is.
             */
            $table->bigInteger('amount_ngwee');

            $table->string('type', 24);

            /* The pair this entry belongs to. Null for an external leg. */
            $table->foreignId('wallet_transfer_id')
                ->nullable()
                ->constrained('wallet_transfers', indexName: 'wallet_entries_transfer_foreign')
                ->cascadeOnDelete();

            /*
             * One provider payment moves a wallet once per kind of movement, and only
             * once. This is the whole idempotency story for the top-up rail: a webhook
             * and a poll arrive for the same payment constantly, and both of them have
             * to be safe rather than one of them lucky.
             *
             * Keyed with the type rather than alone because a withdrawal writes two
             * entries against one payment — what the member asked for, and the
             * provider's fee, which the member bears (config wallets.withdrawals).
             * Unique on the pair still admits exactly one of each.
             */
            $table->foreignId('payment_intent_id')
                ->nullable()
                ->constrained('payment_intents', indexName: 'wallet_entries_payment_intent_foreign')
                ->nullOnDelete();

            /* The other side, for the statement. */
            $table->foreignId('counterparty_wallet_id')
                ->nullable()
                ->constrained('wallets', indexName: 'wallet_entries_counterparty_foreign')
                ->nullOnDelete();

            /* The SavingsTransaction / SocialFundTransaction / LoanTransaction this produced. */
            $table->nullableMorphs('posted_ledger', 'wallet_entries_posted_ledger_index');

            /* The entry this one undoes, for a Reversal. */
            $table->foreignId('reverses_wallet_entry_id')
                ->nullable()
                ->constrained('wallet_entries', indexName: 'wallet_entries_reverses_foreign')
                ->nullOnDelete();

            $table->string('source', 16);

            $table->date('occurred_on');
            $table->text('note')->nullable();

            $table->foreignId('recorded_by_member_id')
                ->nullable()
                ->constrained('members', indexName: 'wallet_entries_recorded_by_foreign')
                ->nullOnDelete();
            $table->foreignId('second_approver_member_id')
                ->nullable()
                ->constrained('members', indexName: 'wallet_entries_second_approver_foreign')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['wallet_id', 'occurred_on']);
            $table->index(['cycle_id', 'type']);
            $table->unique(['payment_intent_id', 'type'], 'wallet_entries_payment_intent_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_entries');
        Schema::dropIfExists('wallet_transfers');
        Schema::dropIfExists('wallets');
    }
};
