<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cycles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');

            $table->unsignedTinyInteger('registration_closes_after_month')->default(3);
            $table->unsignedTinyInteger('loan_lockdown_starts_month')->default(10);
            $table->date('final_repayment_date');

            $table->unsignedBigInteger('lockdown_savings_cap_ngwee')->default(50_000);
            $table->unsignedBigInteger('joining_fee_ngwee')->default(100_000);
            $table->unsignedBigInteger('late_joining_fee_ngwee')->default(200_000);
            $table->unsignedBigInteger('social_fund_contribution_ngwee')->default(25_000);
            $table->unsignedBigInteger('min_savings_ngwee')->default(50_000);
            $table->unsignedBigInteger('savings_increment_ngwee')->default(50_000);
            $table->unsignedBigInteger('borrowing_target_ngwee')->default(5_000_000);
            $table->unsignedBigInteger('late_transfer_penalty_per_day_ngwee')->default(10_000);

            $table->unsignedInteger('monthly_interest_bps')->default(500);
            $table->unsignedInteger('social_fund_interest_bps')->default(1000);
            $table->unsignedInteger('missed_installment_penalty_bps')->default(1000);
            $table->unsignedTinyInteger('max_loan_multiple')->default(2);

            $table->string('weekend_trading_policy')->default('next_monday');
            $table->string('status')->default('draft');

            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cycles');
    }
};
