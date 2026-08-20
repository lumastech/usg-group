<?php

namespace App\Http\Controllers\App;

use App\Enums\MemberRole;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Living documentation of the design system at /app/styleguide.
 *
 * It renders the real components rather than screenshots, so a component that
 * breaks shows up here first. The role/permission matrix is generated from the
 * enums, which keeps it honest as permissions change.
 */
class StyleguideController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('app/Styleguide', [
            'roles' => array_map(fn (MemberRole $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
                'is_committee' => $role->isCommittee(),
                'permissions' => array_map(
                    fn (Permission $permission): string => $permission->value,
                    $role->permissions(),
                ),
            ], MemberRole::cases()),

            'permissions' => array_map(fn (Permission $permission): array => [
                'value' => $permission->value,
                'label' => $permission->label(),
                'group' => $permission->group(),
            ], Permission::cases()),
        ]);
    }
}
