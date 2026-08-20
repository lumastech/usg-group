<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();

            $table->date('meeting_date');
            $table->string('type');
            $table->string('subject')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['cycle_id', 'meeting_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meetings');
    }
};
