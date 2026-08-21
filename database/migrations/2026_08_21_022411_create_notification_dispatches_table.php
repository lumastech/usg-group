<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per scheduled notification batch that has already gone out.
 *
 * The daily notification run is idempotent because of this table: the key carries
 * the date it was resolved for, so a second run on the same day — a retry, a manual
 * invocation, a server that woke up twice — finds the row and sends nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key')->unique();
            $table->date('sent_on');
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamps();

            $table->index(['cycle_id', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dispatches');
    }
};
