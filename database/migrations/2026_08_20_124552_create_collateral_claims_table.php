<?php

use App\Enums\CollateralClaimStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collateral_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();

            $table->string('status', 32)->default(CollateralClaimStatus::Draft->value);

            $table->foreignId('prepared_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('second_signer_member_id')->nullable()->constrained('members')->nullOnDelete();

            /* [{description, estimated_value_ngwee}] — itemised to the outstanding value. */
            $table->json('items');
            $table->unsignedBigInteger('claimed_value_ngwee')->default(0);
            $table->unsignedBigInteger('outstanding_at_claim_ngwee')->default(0);

            $table->timestamp('signed_off_at')->nullable();
            $table->timestamp('enforced_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('note', 500)->nullable();

            $table->timestamps();

            $table->index(['loan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collateral_claims');
    }
};
