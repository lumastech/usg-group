<?php

use App\Enums\NotificationChannel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each member wants to be reached by the scheduled notifications.
 *
 * Defaults to email because that is the only channel with a real driver today; a
 * member switches themselves over on /my/settings once the SMS gateway is live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->string('notification_channel', 16)
                ->default(NotificationChannel::Mail->value)
                ->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('notification_channel');
        });
    }
};
