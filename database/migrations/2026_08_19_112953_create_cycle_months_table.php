<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycle_months', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->date('month');

            $table->dateTime('declarations_open_at');
            $table->dateTime('declarations_close_at');
            $table->date('trading_starts_on');
            $table->date('trading_concludes_on');
            $table->date('disbursement_on');

            $table->string('interest_allocation_method')->default('pooled_pro_rata');
            $table->string('status')->default('pending');

            $table->timestamps();

            $table->unique(['cycle_id', 'sequence']);
            $table->unique(['cycle_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_months');
    }
};
