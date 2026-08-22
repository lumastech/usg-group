<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_destinations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32);

            /* Bank accounts. The provider's bank id is a code, not an integer. */
            $table->string('bank_id', 32)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number', 32)->nullable();

            /* Mobile money. */
            $table->string('phone', 24)->nullable();
            $table->string('operator', 16)->nullable();

            /*
             * What the provider says the account is actually called, and how close that
             * is to the member's own name. A mismatch does not block — Zambian accounts
             * legitimately carry maiden names and spouses' wallets — but somebody on the
             * committee has to look at it and say so.
             */
            $table->string('resolved_account_name')->nullable();
            $table->unsignedTinyInteger('name_match_score')->nullable();
            $table->foreignId('name_match_confirmed_by_member_id')
                ->nullable()
                ->constrained('members', indexName: 'payout_dest_name_confirmed_by_foreign')
                ->nullOnDelete();
            $table->timestamp('name_match_confirmed_at')->nullable();

            /* Cached from the provider so a repeat transfer does not re-create it. */
            $table->string('provider_recipient_id', 64)->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('disabled_at')->nullable();

            $table->foreignId('created_by_member_id')
                ->nullable()
                ->constrained('members', indexName: 'payout_dest_created_by_foreign')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['member_id', 'is_default']);
            $table->index(['member_id', 'type']);

            /*
             * One row per account per member, enforced on a hash rather than on the
             * columns themselves: MySQL treats NULLs as distinct, so a unique index
             * across the bank and wallet columns would let the same wallet be added
             * twice simply because the bank half is null. PayoutDestination computes
             * the fingerprint from whichever half is in use.
             *
             * Only one destination per member may be the default. That is an
             * application rule (PayoutDestinationService), because MySQL has no
             * partial unique index to express it.
             */
            $table->char('fingerprint', 40);
            $table->unique(['member_id', 'fingerprint'], 'payout_dest_member_fingerprint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_destinations');
    }
};
