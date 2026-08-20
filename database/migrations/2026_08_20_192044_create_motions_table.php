<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('motions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained()->cascadeOnDelete();

            /* Null for a no-confidence motion raised outside a meeting, which the
               constitution allows so an officer cannot bury it by not calling one. */
            $table->foreignId('meeting_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type');
            $table->string('subject');
            $table->foreignId('target_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('proposed_by_member_id')->constrained('members')->cascadeOnDelete();

            /* Show of hands: tallies only. The constitution takes votes by raised hand,
               so there is deliberately no per-member vote record to write. */
            $table->unsignedInteger('votes_for')->default(0);
            $table->unsignedInteger('votes_against')->default(0);
            $table->unsignedInteger('abstentions')->default(0);

            $table->string('threshold_basis');

            /* Snapshotted when the motion is decided: how many the base held that day
               and how many votes that made necessary. Frozen for the same reason the
               trading session freezes its conclude date — a member joining next month
               must not be able to turn a passed motion into a failed one. */
            $table->unsignedInteger('eligible_count')->nullable();
            $table->unsignedInteger('votes_needed')->nullable();

            $table->boolean('passed')->nullable();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['cycle_id', 'type']);
            $table->index('meeting_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('motions');
    }
};
