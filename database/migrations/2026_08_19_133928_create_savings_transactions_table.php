<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('savings_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_month_id')->constrained()->cascadeOnDelete();

            $table->string('type')->default('contribution');
            $table->bigInteger('amount_ngwee');
            $table->unsignedBigInteger('declared_amount_ngwee')->nullable();

            $table->foreignId('recorded_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('source')->default('manual');
            $table->date('occurred_on');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['member_id', 'cycle_month_id']);
            $table->index(['cycle_month_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_transactions');
    }
};
