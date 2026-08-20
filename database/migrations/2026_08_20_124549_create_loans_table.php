<?php

use App\Enums\LoanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('principal_ngwee');
            $table->unsignedTinyInteger('tenor_months');

            /* Set when the deadline forced a shorter tenor than the principal earns. */
            $table->boolean('schedule_compressed')->default(false);

            $table->string('status', 32)->default(LoanStatus::Requested->value);
            $table->timestamp('requested_at');

            /* Both approvers are kept: the two-person rule is only meaningful if the pair is auditable. */
            $table->foreignId('approved_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('second_approver_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();

            $table->timestamp('disbursed_at')->nullable();
            $table->foreignId('disbursed_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('disbursement_cycle_month_id')->nullable()->constrained('cycle_months')->nullOnDelete();

            /* Position in that month's FIFO queue, and the typed reason if it was jumped. */
            $table->unsignedSmallInteger('disbursement_position')->nullable();
            $table->string('out_of_order_reason', 500)->nullable();

            $table->timestamp('settled_at')->nullable();
            $table->timestamp('defaulted_at')->nullable();

            /* One loan at a time, unless a committee member records why not. */
            $table->boolean('discretion_override')->default(false);
            $table->string('discretion_note', 500)->nullable();

            /* Denormalised for listing screens; always rebuildable from loan_transactions. */
            $table->bigInteger('current_balance_ngwee')->default(0);

            $table->timestamps();

            $table->index(['cycle_id', 'status']);
            $table->index(['member_id', 'status']);
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
