<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('motion_id')->constrained()->cascadeOnDelete();

            $table->string('section_reference');
            $table->text('current_text');
            $table->text('proposed_text');
            $table->date('effective_date');

            $table->timestamps();

            $table->unique('motion_id');
            $table->index('cycle_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amendments');
    }
};
