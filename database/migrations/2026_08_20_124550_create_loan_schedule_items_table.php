<?php

use App\Enums\LoanScheduleItemStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_schedule_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_month_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');

            $table->date('due_month');
            $table->date('due_on');

            /*
             * The schedule the member was handed at disbursement, kept beside what is
             * actually expected now. Interest is charged on the reducing balance, so a
             * month paid late or short changes every later installment — the member is
             * still owed the original figures to compare against.
             */
            $table->unsignedBigInteger('original_principal_ngwee');
            $table->unsignedBigInteger('original_interest_ngwee');
            $table->unsignedBigInteger('original_amount_due_ngwee');

            $table->unsignedBigInteger('principal_due_ngwee');
            $table->unsignedBigInteger('interest_due_ngwee');
            $table->unsignedBigInteger('amount_due_ngwee');
            $table->unsignedBigInteger('amount_paid_ngwee')->default(0);

            $table->timestamp('paid_at')->nullable();
            $table->string('status', 32)->default(LoanScheduleItemStatus::Pending->value);

            $table->timestamps();

            $table->unique(['loan_id', 'sequence']);
            $table->index(['loan_id', 'status']);
            $table->index('cycle_month_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_schedule_items');
    }
};
