<?php

use App\Enums\GrantClaimStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unity_baby_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('child_name')->nullable();
            $table->date('born_on');
            $table->date('claim_date');
            $table->string('status', 16)->default(GrantClaimStatus::Submitted->value);
            $table->unsignedBigInteger('amount_ngwee');

            $table->foreignId('first_approver_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('second_approver_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['cycle_id', 'status']);
            $table->index(['member_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unity_baby_claims');
    }
};
