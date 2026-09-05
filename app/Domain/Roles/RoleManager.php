<?php

namespace App\Domain\Roles;

use App\Enums\MemberRole;
use App\Enums\Permission as PermissionEnum;
use App\Exceptions\DomainRuleException;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

/**
 * The one place a role is created, re-scoped or removed.
 *
 * Roles are only bundles — every guard in the portal asks for a permission — so this
 * class decides nothing about authority beyond which permissions sit in which bundle.
 * What it does hold are the three rules that keep the bundles honest:
 *
 * 1. The constitution's offices (App\Enums\MemberRole) may be re-scoped but never
 *    renamed or deleted. Code assigns them by name — CommitteeRoleSync grants
 *    `treasurer` when a term is recorded, MemberInviter grants `member` — so a
 *    renamed office silently stops being granted to anybody.
 * 2. The administrator's bundle is not editable at all. It is defined as "every
 *    permission", it is the only role holding `roles.manage`, and trimming it is how
 *    a group locks itself out of its own portal with no way back in through the UI.
 * 3. An office whose permissions have been changed here is marked
 *    `permissions_customised_at`, and RoleSeeder then leaves its bundle alone. Without
 *    that, the next deploy would quietly undo the committee's decision.
 *
 * Everything is logged to the `roles` activity log: who may do what is exactly the
 * kind of change that has to be answerable afterwards.
 */
class RoleManager
{
    public function __construct(protected PermissionRegistrar $registrar) {}

    /**
     * Adds a role of the administrator's own.
     *
     * @param  array<int, string>  $permissions
     *
     * @throws DomainRuleException
     */
    public function create(string $name, ?string $description, array $permissions, ?User $by = null): Role
    {
        $name = $this->normaliseName($name);

        $this->assertNameAvailable($name);

        $permissions = $this->assertKnownPermissions($permissions);

        $role = DB::transaction(function () use ($name, $description, $permissions, $by): Role {
            $role = Role::create([
                'name' => $name,
                'guard_name' => 'web',
                'description' => $this->normaliseDescription($description),
                'is_system' => false,
            ]);

            $role->syncPermissions($permissions);

            $this->log($role, 'role.created', "Created the role {$role->label()}", $by, [
                'permissions' => $permissions,
            ]);

            return $role;
        });

        $this->registrar->forgetCachedPermissions();

        return $role->load('permissions');
    }

    /**
     * Re-scopes a role, and renames it when it is one of the administrator's own.
     *
     * @param  array<int, string>  $permissions
     *
     * @throws DomainRuleException
     */
    public function update(Role $role, ?string $name, ?string $description, array $permissions, ?User $by = null): Role
    {
        $this->assertEditable($role);

        $permissions = $this->assertKnownPermissions($permissions);

        $name = $name === null || $role->is_system ? $role->name : $this->normaliseName($name);

        if ($name !== $role->name) {
            $this->assertNameAvailable($name, $role);
        }

        $before = $role->permissions->pluck('name')->sort()->values()->all();

        $role = DB::transaction(function () use ($role, $name, $description, $permissions, $before, $by): Role {
            $role->forceFill([
                'name' => $name,
                'description' => $this->normaliseDescription($description),
                // An office the committee has re-scoped is theirs from now on: the
                // seeder stops overwriting its bundle on the next deploy.
                'permissions_customised_at' => $role->is_system
                    ? ($role->permissions_customised_at ?? now())
                    : null,
            ])->save();

            $role->syncPermissions($permissions);

            $this->log($role, 'role.updated', "Changed what {$role->label()} may do", $by, [
                'granted' => array_values(array_diff($permissions, $before)),
                'revoked' => array_values(array_diff($before, $permissions)),
                'permissions' => $permissions,
            ]);

            return $role;
        });

        $this->registrar->forgetCachedPermissions();

        return $role->load('permissions');
    }

    /**
     * Removes one of the administrator's own roles.
     *
     * Anyone holding it loses it — spatie clears the assignments with the row — so the
     * count of holders is logged alongside, and the screen shows it before confirming.
     *
     * @throws DomainRuleException
     */
    public function delete(Role $role, ?User $by = null): void
    {
        if ($role->is_system) {
            throw new DomainRuleException(
                "{$role->label()} is one of the group's offices and cannot be deleted. Change what it may do instead.",
            );
        }

        $holders = $role->users()->count();

        DB::transaction(function () use ($role, $holders, $by): void {
            $this->log($role, 'role.deleted', "Deleted the role {$role->label()}", $by, [
                'permissions' => $role->permissions->pluck('name')->values()->all(),
                'holders' => $holders,
            ]);

            $role->delete();
        });

        $this->registrar->forgetCachedPermissions();
    }

    /**
     * Puts a re-scoped office back on the bundle MemberRole defines for it.
     *
     * The escape hatch for rule 3 above: clearing the mark hands the office back to
     * the seeder, so the constitution's wiring is one click away rather than a
     * database edit.
     *
     * @throws DomainRuleException
     */
    public function resetToConstitution(Role $role, ?User $by = null): Role
    {
        $office = MemberRole::tryFrom($role->name);

        if (! $role->is_system || $office === null) {
            throw new DomainRuleException("{$role->label()} is not one of the group's offices, so it has no default to go back to.");
        }

        $permissions = array_map(
            fn (PermissionEnum $permission): string => $permission->value,
            $office->permissions(),
        );

        $role = DB::transaction(function () use ($role, $permissions, $by): Role {
            $role->forceFill(['permissions_customised_at' => null])->save();

            $role->syncPermissions($permissions);

            $this->log($role, 'role.reset', "Put {$role->label()} back on the constitution's permissions", $by, [
                'permissions' => $permissions,
            ]);

            return $role;
        });

        $this->registrar->forgetCachedPermissions();

        return $role->load('permissions');
    }

    /**
     * Whether this role's permissions may be changed at all.
     *
     * The administrator's bundle is every permission by definition; the screen renders
     * it read-only from this same answer.
     */
    public function isEditable(Role $role): bool
    {
        return $role->name !== MemberRole::Admin->value;
    }

    /** @throws DomainRuleException */
    protected function assertEditable(Role $role): void
    {
        if (! $this->isEditable($role)) {
            throw new DomainRuleException(
                'The administrator holds every permission by definition. Trimming it would leave nobody able to put it back.',
            );
        }
    }

    /**
     * Roles are matched by name in code and in the middleware, so the name has to be
     * a stable handle rather than a sentence: lower snake_case, as the offices are.
     *
     * @throws DomainRuleException
     */
    protected function normaliseName(string $name): string
    {
        $normalised = Str::of($name)
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();

        if ($normalised === '') {
            throw new DomainRuleException('A role needs a name made of letters or numbers.');
        }

        return $normalised;
    }

    protected function normaliseDescription(?string $description): ?string
    {
        $description = trim((string) $description);

        return $description === '' ? null : $description;
    }

    /** @throws DomainRuleException */
    protected function assertNameAvailable(string $name, ?Role $ignoring = null): void
    {
        $taken = Role::query()
            ->where('name', $name)
            ->when($ignoring !== null, fn ($query) => $query->whereKeyNot($ignoring->getKey()))
            ->exists();

        if ($taken) {
            throw new DomainRuleException("A role called \"{$name}\" already exists.");
        }
    }

    /**
     * Permissions are the application's own vocabulary, so only names that appear in
     * App\Enums\Permission may be granted. A typo would otherwise create a permission
     * nothing ever checks, which reads as authority that isn't there.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     *
     * @throws DomainRuleException
     */
    protected function assertKnownPermissions(array $permissions): array
    {
        $permissions = collect($permissions)->filter()->unique()->values();

        $unknown = $permissions->diff(PermissionEnum::values());

        if ($unknown->isNotEmpty()) {
            throw new DomainRuleException('Unknown permission: '.$unknown->first().'.');
        }

        // `roles.manage` is the permission that grants permissions. Handing it to a
        // second bundle turns every other rule here into a suggestion.
        if ($permissions->contains(PermissionEnum::RolesManage->value)) {
            throw new DomainRuleException(
                'Managing roles stays with the administrator; it cannot be granted to another role.',
            );
        }

        return $permissions->sort()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    protected function log(Role $role, string $event, string $message, ?User $by, array $properties = []): void
    {
        activity('roles')
            ->performedOn($role)
            ->causedBy($by)
            ->withProperties([
                'role' => $role->name,
                'is_system' => $role->is_system,
                ...$properties,
            ])
            ->event($event)
            ->log($message);
    }
}
