<?php

namespace Database\Seeders;

use App\Enums\MemberRole;
use App\Enums\Permission as PermissionEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every role and permission, then grants each role its bundle.
 *
 * Safe to re-run: a role keeps the permissions still listed in MemberRole::permissions()
 * and loses the ones that are not, so revoking an ability is a one-line edit plus a
 * reseed. Roles that no longer appear in the enum at all are removed, which is what
 * keeps a renamed office from lingering with stale grants.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);

        // findOrCreate() reads through the registrar's cache, so a stale cache would
        // hide existing rows and turn this into a duplicate-key insert.
        $registrar->forgetCachedPermissions();

        foreach (PermissionEnum::values() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Permissions created in this same run stay invisible to syncPermissions()
        // until the cache is dropped again.
        $registrar->forgetCachedPermissions();

        foreach (MemberRole::cases() as $case) {
            $role = Role::findOrCreate($case->value, 'web');

            // MySQL compares case-insensitively, so findOrCreate() happily returns a
            // row still spelled "Chairperson". Force the enum's exact casing.
            if ($role->name !== $case->value) {
                $role->forceFill(['name' => $case->value])->save();
            }

            $role->syncPermissions(
                array_map(fn (PermissionEnum $permission): string => $permission->value, $case->permissions()),
            );
        }

        $this->pruneRetiredRoles();

        $registrar->forgetCachedPermissions();
    }

    /**
     * Drops roles that are no longer offices, along with their assignments.
     *
     * Callers must reassign afterwards; DatabaseSeeder runs AdminSeeder and
     * UnityCycleSeeder after this one for exactly that reason.
     */
    protected function pruneRetiredRoles(): void
    {
        Role::query()
            ->whereNotIn('name', MemberRole::values())
            ->get()
            ->each(fn (Role $role) => $role->delete());
    }
}
