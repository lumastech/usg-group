<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('committee_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();

            $table->string('role');
            $table->date('started_at');
            $table->date('ended_at')->nullable();
            $table->string('end_reason')->nullable();

            /* The day the officer gave notice. The constitution's one month runs from
               here, so it is kept even after the term has ended. */
            $table->date('resignation_notice_date')->nullable();

            /* Set when the committee waived the notice period, with the reason they
               gave — a waiver has to be explicable at the next meeting. */
            $table->text('notice_waiver_note')->nullable();

            $table->timestamps();

            $table->index(['cycle_id', 'role', 'ended_at']);
            $table->index(['member_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('committee_terms');
    }
};
