<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records why a member's status changed, not just that it did.
 *
 * `status_effective_on` is the date the change takes effect for payout purposes;
 * `status_changed_at` is when the committee actually recorded it. They differ
 * whenever a death or departure is entered after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->timestamp('status_changed_at')->nullable()->after('status_effective_on');
            $table->text('status_reason')->nullable()->after('status_changed_at');
            $table->string('expulsion_ground')->nullable()->after('status_reason');
            $table->date('date_of_death')->nullable()->after('expulsion_ground');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['status_changed_at', 'status_reason', 'expulsion_ground', 'date_of_death']);
        });
    }
};
