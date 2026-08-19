<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->string('action');

            $table->foreignId('requested_by_member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('first_approver_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('second_approver_member_id')->nullable()->constrained('members')->nullOnDelete();

            $table->dateTime('first_approved_at')->nullable();
            $table->dateTime('second_approved_at')->nullable();
            $table->dateTime('rejected_at')->nullable();
            $table->foreignId('rejected_by_member_id')->nullable()->constrained('members')->nullOnDelete();

            $table->string('status')->default('pending');
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
