<?php

namespace App\Http\Controllers\App;

use App\Domain\Roles\RoleManager;
use App\Enums\MemberRole;
use App\Enums\Permission as PermissionEnum;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreRoleRequest;
use App\Http\Requests\Settings\UpdateRoleRequest;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Roles and what each one may do.
 *
 * Nothing here decides authority: it renders the bundles and hands changes to
 * RoleManager, which holds every rule about which of them may be touched. The
 * `abilities` on each row are that same service's answers, so a button is only
 * offered where the write would actually be accepted.
 */
class RoleController extends Controller
{
    public function __construct(protected RoleManager $roles) {}

    public function index(): Response
    {
        return Inertia::render('app/settings/Roles', [
            'roles' => $this->roles(),
            'permissionGroups' => $this->permissionGroups(),
        ]);
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        try {
            $role = $this->roles->create(
                $request->string('name')->toString(),
                $request->input('description'),
                $request->permissions(),
                $request->user(),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['name' => $exception->getMessage()]);
        }

        return back()->with('success', "{$role->label()} has been added.");
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        try {
            $role = $this->roles->update(
                $role,
                $request->input('name'),
                $request->input('description'),
                $request->permissions(),
                $request->user(),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['permissions' => $exception->getMessage()]);
        }

        return back()->with('success', "{$role->label()} has been updated.");
    }

    /** Puts one of the offices back on the permissions MemberRole defines for it. */
    public function reset(Role $role): RedirectResponse
    {
        try {
            $role = $this->roles->resetToConstitution($role, request()->user());
        } catch (DomainRuleException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "{$role->label()} is back on the constitution's permissions.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        $label = $role->label();

        try {
            $this->roles->delete($role, request()->user());
        } catch (DomainRuleException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "{$label} has been deleted.");
    }

    /**
     * Every role with its bundle, its holders and what may be done to it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function roles(): array
    {
        return Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label(),
                'description' => $role->description,
                'is_system' => $role->is_system,
                /* An office the committee has re-scoped: the seeder no longer
                   overwrites its bundle, and the screen offers to put it back. */
                'customised' => $role->permissions_customised_at !== null,
                'holders' => $role->users_count,
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
                'abilities' => [
                    'editPermissions' => $this->roles->isEditable($role),
                    'rename' => ! $role->is_system,
                    'delete' => ! $role->is_system,
                    'reset' => $role->is_system
                        && $role->permissions_customised_at !== null
                        && MemberRole::tryFrom($role->name) !== null,
                ],
            ])
            ->all();
    }

    /**
     * The permissions on offer, grouped by the section of the portal they belong to.
     *
     * `roles.manage` is left out entirely: RoleManager refuses to grant it to any
     * bundle but the administrator's, so offering the checkbox would only be a way
     * of finding that out the hard way.
     *
     * @return array<int, array{key: string, label: string, permissions: array<int, array{name: string, label: string}>}>
     */
    protected function permissionGroups(): array
    {
        return collect(PermissionEnum::cases())
            ->reject(fn (PermissionEnum $permission): bool => $permission === PermissionEnum::RolesManage)
            ->groupBy(fn (PermissionEnum $permission): string => $permission->group())
            ->map(fn ($permissions, string $group): array => [
                'key' => $group,
                'label' => Str::headline($group),
                'permissions' => $permissions
                    ->map(fn (PermissionEnum $permission): array => [
                        'name' => $permission->value,
                        'label' => $permission->label(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
