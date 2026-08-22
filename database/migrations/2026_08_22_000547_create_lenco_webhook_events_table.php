<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lenco_webhook_events', function (Blueprint $table): void {
            $table->id();

            $table->string('event', 64);

            /*
             * The provider's own id for the transaction the event is about. Unique, so
             * a redelivery — and the provider retries every 30 minutes for 24 hours
             * until it sees a 2xx — is a cheap no-op rather than a second ledger entry.
             */
            $table->string('event_key', 128)->unique();

            $table->string('reference', 64)->nullable();
            $table->string('signature', 191)->nullable();

            $table->json('payload');

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->index(['event', 'processed_at']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lenco_webhook_events');
    }
};
