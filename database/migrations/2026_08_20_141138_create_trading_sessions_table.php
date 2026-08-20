<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_month_id')->constrained()->cascadeOnDelete();

            /* The adjusted 7th, copied off the cycle month when the session opens, so
               the penalty arithmetic on a concluded session can never be re-dated by a
               later change to the cycle's weekend policy. */
            $table->date('scheduled_conclude_date');

            $table->string('status')->default('open');
            $table->foreignId('concluded_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('concluded_at')->nullable();

            $table->timestamps();

            $table->unique('cycle_month_id');
            $table->index(['cycle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_sessions');
    }
};
