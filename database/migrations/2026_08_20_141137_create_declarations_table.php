<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_month_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('saving_amount_ngwee')->default(0);
            $table->unsignedBigInteger('loan_repayment_amount_ngwee')->default(0);
            $table->unsignedBigInteger('loan_requested_amount_ngwee')->default(0);

            /* saving + repayment − loan requested. Negative when the member is taking
               more out of the table than they are bringing to it, which is normal for
               a borrowing month, so this column is signed. */
            $table->bigInteger('total_expected_payment_ngwee')->default(0);

            $table->timestamp('submitted_at')->nullable();
            $table->boolean('is_late')->default(false);
            $table->string('status')->default('submitted');
            $table->foreignId('recorded_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            /* One declaration per member per month is the rule, enforced here as well
               as in the service so a double submit cannot slip through a race. */
            $table->unique(['cycle_month_id', 'member_id']);
            $table->index(['cycle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('declarations');
    }
};
