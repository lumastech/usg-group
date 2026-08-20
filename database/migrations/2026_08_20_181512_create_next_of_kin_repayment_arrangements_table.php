<?php

use App\Enums\SettlementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The terms a next of kin agreed to for a debt a deceased member left behind.
     *
     * The estate is never sent a negative payout. Where the loan outruns the savings,
     * the committee and the nominated next of kin agree how it is repaid, and that
     * agreement is this row.
     */
    public function up(): void
    {
        Schema::create('next_of_kin_repayment_arrangements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('next_of_kin_id')->nullable()->constrained('next_of_kin')->nullOnDelete();

            $table->unsignedBigInteger('amount_owed_ngwee');
            $table->text('agreed_terms');
            $table->string('status', 16)->default(SettlementStatus::Outstanding->value);

            $table->json('breakdown');

            $table->date('agreed_on')->nullable();
            /* Named explicitly: the table's own name leaves no room for MySQL's 64-character limit. */
            $table->foreignId('recorded_by_member_id')->nullable()
                ->constrained('members', indexName: 'nok_arrangements_recorded_by_foreign')->nullOnDelete();
            $table->foreignId('second_approver_member_id')->nullable()
                ->constrained('members', indexName: 'nok_arrangements_second_approver_foreign')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique('member_id');
            $table->index(['cycle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('next_of_kin_repayment_arrangements');
    }
};
