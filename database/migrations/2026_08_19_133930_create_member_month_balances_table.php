<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_month_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_month_id')->constrained()->cascadeOnDelete();

            $table->bigInteger('savings_ngwee')->default(0);
            $table->bigInteger('cumulative_savings_ngwee')->default(0);
            $table->bigInteger('interest_earned_ngwee')->default(0);
            $table->bigInteger('cumulative_interest_earned_ngwee')->default(0);
            $table->bigInteger('cumulative_interest_paid_ngwee')->default(0);

            $table->bigInteger('loan_balance_ngwee')->default(0);
            $table->bigInteger('social_loan_balance_ngwee')->default(0);
            $table->bigInteger('member_value_ngwee')->default(0);
            $table->bigInteger('net_value_ngwee')->default(0);

            $table->bigInteger('two_times_savings_ngwee')->default(0);
            $table->bigInteger('eligible_to_borrow_ngwee')->default(0);
            $table->bigInteger('borrowed_to_date_ngwee')->default(0);
            $table->bigInteger('borrowing_target_balance_ngwee')->default(0);

            $table->timestamps();

            $table->unique(['member_id', 'cycle_month_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_month_balances');
    }
};
