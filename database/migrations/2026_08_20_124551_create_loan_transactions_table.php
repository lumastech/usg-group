<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_month_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by_member_id')->nullable()->constrained('members')->nullOnDelete();

            $table->string('type', 40);
            $table->unsignedBigInteger('amount_ngwee');
            $table->date('occurred_on');
            $table->bigInteger('balance_after_ngwee');

            /*
             * How a repayment was split. Penalties clear first, then the month's interest,
             * then principal — which is what makes interest paid, the interest pool and the
             * remaining principal all derivable from the ledger alone.
             */
            $table->unsignedBigInteger('principal_portion_ngwee')->default(0);
            $table->unsignedBigInteger('interest_portion_ngwee')->default(0);
            $table->unsignedBigInteger('penalty_portion_ngwee')->default(0);

            $table->string('notes', 500)->nullable();

            $table->timestamps();

            $table->index(['loan_id', 'occurred_on']);
            $table->index(['type', 'occurred_on']);
            $table->index(['cycle_month_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_transactions');
    }
};
