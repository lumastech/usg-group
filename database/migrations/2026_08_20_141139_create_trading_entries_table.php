<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trading_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('declaration_id')->nullable()->constrained()->nullOnDelete();

            /* Money in: what the declaration promised, and what was actually handed
               over at the table. Money out: the loan the member is queued to receive. */
            $table->unsignedBigInteger('expected_in_ngwee')->default(0);
            $table->unsignedBigInteger('actual_in_ngwee')->default(0);
            $table->timestamp('received_at')->nullable();

            $table->unsignedBigInteger('expected_out_ngwee')->default(0);
            $table->unsignedBigInteger('actual_out_ngwee')->default(0);
            $table->timestamp('disbursed_at')->nullable();

            $table->bigInteger('variance_ngwee')->default(0);
            $table->unsignedInteger('penalty_days')->default(0);

            /* Split of the money in, so concluding the session knows how much of it is
               savings and how much clears a loan without re-reading the declaration. */
            $table->unsignedBigInteger('savings_portion_ngwee')->default(0);
            $table->unsignedBigInteger('repayment_portion_ngwee')->default(0);

            $table->timestamps();

            $table->unique(['trading_session_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_entries');
    }
};
