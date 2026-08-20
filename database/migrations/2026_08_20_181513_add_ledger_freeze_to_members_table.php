<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a member's ledgers were frozen by their payout being executed.
     *
     * Executing a payout settles a position that was computed from the ledgers, so
     * from that moment nothing may move on them again — otherwise the voucher in the
     * member's hand and the ledger behind it drift apart. Null means not frozen.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->timestamp('ledgers_frozen_at')->nullable()->after('date_of_death');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('ledgers_frozen_at');
        });
    }
};
