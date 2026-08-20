<?php

use App\Enums\SettlementStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a member still owes the group after their closure was computed.
     *
     * A member who leaves early or is expelled owing more than they saved cannot be
     * paid a negative amount, so the shortfall is recorded here and chased.
     */
    public function up(): void
    {
        Schema::create('member_debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('case', 24);
            $table->unsignedBigInteger('amount_owed_ngwee');
            $table->string('status', 16)->default(SettlementStatus::Outstanding->value);

            $table->json('breakdown');

            $table->foreignId('recorded_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('second_approver_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique('member_id');
            $table->index(['cycle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_debts');
    }
};
