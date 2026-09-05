<?php

namespace Database\Seeders;

use App\Enums\MemberRole;
use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every role and permission, then grants each role its bundle.
 *
 * Safe to re-run: a role keeps the permissions still listed in MemberRole::permissions()
 * and loses the ones that are not, so revoking an ability is a one-line edit plus a
 * reseed. Roles that no longer appear in the enum at all are removed, which is what
 * keeps a renamed office from lingering with stale grants.
 *
 * Two things this deliberately does not touch, both written by App\Domain\Roles\RoleManager:
 * roles the administrator added (`is_system = false`), and offices whose bundle the
 * committee has changed on the roles screen (`permissions_customised_at`). Re-syncing
 * either would undo a decision on the next deploy, without anybody being told. The
 * administrator's own bundle is always re-synced, because it is defined as every
 * permission and RoleManager refuses to edit it.
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
            /** @var Role $role */
            $role = Role::findOrCreate($case->value, 'web');

            // MySQL compares case-insensitively, so findOrCreate() happily returns a
            // row still spelled "Chairperson". Force the enum's exact casing.
            $role->forceFill([
                'name' => $case->value,
                'is_system' => true,
            ])->save();

            if ($this->keepsItsOwnPermissions($role, $case)) {
                continue;
            }

            $role->syncPermissions(
                array_map(fn (PermissionEnum $permission): string => $permission->value, $case->permissions()),
            );
        }

        $this->pruneRetiredRoles();

        $registrar->forgetCachedPermissions();
    }

    /**
     * Whether this office's bundle has been re-scoped on the roles screen.
     *
     * The administrator is exempt: its bundle is every permission by definition, and
     * RoleManager will not let anybody narrow it.
     */
    protected function keepsItsOwnPermissions(Role $role, MemberRole $case): bool
    {
        return $case !== MemberRole::Admin && $role->permissions_customised_at !== null;
    }

    /**
     * Drops roles that are no longer offices, along with their assignments.
     *
     * Scoped to system roles: a legacy row from an older seed is swept up, while a
     * role the administrator added on the roles screen survives every reseed.
     *
     * Callers must reassign afterwards; DatabaseSeeder runs AdminSeeder and
     * UnityCycleSeeder after this one for exactly that reason.
     */
    protected function pruneRetiredRoles(): void
    {
        Role::query()
            ->where('is_system', true)
            ->whereNotIn('name', MemberRole::values())
            ->get()
            ->each(fn (Role $role) => $role->delete());
    }
}
