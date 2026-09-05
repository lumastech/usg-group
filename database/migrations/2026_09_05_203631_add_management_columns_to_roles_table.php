<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the administrator define roles of their own alongside the constitution's offices.
 *
 * `is_system` defaults to true on purpose: RoleSeeder prunes every role it does not
 * recognise, which is what stops a renamed office lingering with live grants. Only
 * RoleManager writes false, so a role the administrator created is the one thing the
 * prune leaves alone — anything else, including a legacy row from an older seed, is
 * still treated as the system's own and swept away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->string('description')->nullable()->after('guard_name');
            $table->boolean('is_system')->default(true)->after('description');
            $table->timestamp('permissions_customised_at')->nullable()->after('is_system');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['description', 'is_system', 'permissions_customised_at']);
        });
    }

    protected function table(): string
    {
        return config('permission.table_names.roles', 'roles');
    }
};
