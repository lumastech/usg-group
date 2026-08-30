<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The committee's "ask": a declaration nobody has approved is a request, and no
     * money may be collected against it.
     *
     * Stamped on the row rather than read off the status, because a declaration
     * approved once the trading session has opened is already Locked — the status
     * cannot carry the approval and the payment gate still has to know about it.
     */
    public function up(): void
    {
        Schema::table('declarations', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('is_late');
            $table->foreignId('approved_by_member_id')->nullable()->after('recorded_by_member_id')
                ->constrained('members')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('declarations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_member_id');
            $table->dropColumn('approved_at');
        });
    }
};
