<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_fund_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_month_id')->nullable()->constrained()->nullOnDelete();

            /* Null for entries that belong to the group rather than to one member. */
            $table->foreignId('member_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('type', 32);

            /* Signed: inflows positive, outflows negative, so the balance is a plain SUM. */
            $table->bigInteger('amount_ngwee');
            $table->date('occurred_on');

            /* What the entry mirrors — a loan penalty, a claim, an apportionment share. */
            $table->nullableMorphs('reference');

            $table->foreignId('recorded_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('second_approver_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['cycle_id', 'type']);
            $table->index(['member_id', 'type']);
            $table->index(['cycle_id', 'occurred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_fund_transactions');
    }
};
