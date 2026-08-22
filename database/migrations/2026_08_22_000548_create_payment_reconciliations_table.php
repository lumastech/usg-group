<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->nullable()->constrained()->nullOnDelete();

            $table->date('for_date');

            $table->unsignedInteger('collections_count')->default(0);
            $table->bigInteger('collections_ngwee')->default(0);
            $table->unsignedInteger('transfers_count')->default(0);
            $table->bigInteger('transfers_ngwee')->default(0);
            $table->bigInteger('fees_ngwee')->default(0);

            /* What the provider's account balance said at the moment of the run. */
            $table->bigInteger('provider_balance_ngwee')->nullable();

            /*
             * Anything on one side and not the other: a payment the provider knows
             * about that we never posted, or a posted payment the provider has no
             * record of. This is what catches the money that moved while the webhook
             * endpoint was down and the poller had given up.
             */
            $table->json('unmatched')->nullable();
            $table->unsignedInteger('unmatched_count')->default(0);

            $table->foreignId('run_by_member_id')
                ->nullable()
                ->constrained('members', indexName: 'payment_reconciliations_run_by_foreign')
                ->nullOnDelete();
            $table->timestamp('ran_at');

            $table->timestamps();

            $table->unique('for_date');
            $table->index(['cycle_id', 'for_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliations');
    }
};
