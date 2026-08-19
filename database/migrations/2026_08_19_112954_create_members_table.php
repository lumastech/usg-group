<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('member_number');
            $table->string('full_name');
            $table->string('nrc_number')->nullable();
            $table->text('physical_address')->nullable();
            $table->string('phone')->nullable();

            $table->string('next_of_kin_name')->nullable();
            $table->string('next_of_kin_phone')->nullable();
            $table->string('next_of_kin_relationship')->nullable();

            $table->boolean('is_diaspora')->default(false);

            $table->string('status')->default('active');
            $table->date('status_effective_on')->nullable();

            $table->date('joined_on');
            $table->unsignedTinyInteger('joining_month_sequence')->default(1);
            $table->unsignedBigInteger('joining_fee_ngwee')->default(0);
            $table->boolean('joining_fee_paid')->default(false);

            $table->timestamps();

            $table->unique(['cycle_id', 'member_number']);
            $table->unique(['cycle_id', 'nrc_number']);
            $table->index(['cycle_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
