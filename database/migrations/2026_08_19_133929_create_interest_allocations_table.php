<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cycle_month_id')->constrained()->cascadeOnDelete();

            $table->string('method');
            $table->unsignedBigInteger('pool_total_ngwee')->default(0);
            $table->unsignedBigInteger('member_basis_ngwee')->default(0);
            $table->unsignedBigInteger('pool_basis_ngwee')->default(0);
            $table->bigInteger('amount_ngwee')->default(0);
            $table->unsignedInteger('residual_ngwee')->default(0);

            $table->timestamps();

            $table->unique(['member_id', 'cycle_month_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_allocations');
    }
};
