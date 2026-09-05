<?php

use App\Domain\Roles\RoleManager;
use App\Enums\MemberRole;
use App\Enums\Permission as PermissionEnum;
use App\Exceptions\DomainRuleException;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * The administrator defining roles and re-scoping the offices.
 *
 * Roles are bundles and permissions are what the application checks, so these tests
 * are really about three guarantees: an office cannot lose its name or its existence,
 * the administrator's bundle cannot be trimmed, and a re-scoped office survives the
 * next reseed.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->manager = app(RoleManager::class);
});

function roleAdministrator(): User
{
    $user = User::factory()->create();
    $user->assignRole(MemberRole::Admin->value);

    test()->actingAs($user);

    return $user;
}

it('lets the administrator add a role with the permissions it needs', function () {
    roleAdministrator();

    $this->post(route('app.settings.roles.store'), [
        'name' => 'Loans clerk',
        'description' => 'Captures repayments at the table.',
        'permissions' => [
            PermissionEnum::LoansView->value,
            PermissionEnum::LoansRecordRepayment->value,
        ],
    ])->assertRedirect();

    $role = Role::findByName('loans_clerk');

    expect($role->is_system)->toBeFalse()
        ->and($role->description)->toBe('Captures repayments at the table.')
        ->and($role->permissions->pluck('name')->sort()->values()->all())
        ->toBe([PermissionEnum::LoansRecordRepayment->value, PermissionEnum::LoansView->value]);
});

it('turns a role name into the handle code matches on', function () {
    roleAdministrator();

    $this->post(route('app.settings.roles.store'), [
        'name' => '  Vice   Secretary! ',
        'permissions' => [],
    ])->assertRedirect();

    expect(Role::where('name', 'vice_secretary')->exists())->toBeTrue();
});

it('refuses a second role with the same name', function () {
    roleAdministrator();

    $this->post(route('app.settings.roles.store'), ['name' => 'auditor', 'permissions' => []]);

    $this->post(route('app.settings.roles.store'), ['name' => 'Auditor', 'permissions' => []])
        ->assertSessionHasErrors('name');

    expect(Role::where('name', 'auditor')->count())->toBe(1);
});

it('refuses a permission the application never checks', function () {
    roleAdministrator();

    $this->post(route('app.settings.roles.store'), [
        'name' => 'clerk',
        'permissions' => ['loans.forge'],
    ])->assertSessionHasErrors('permissions.0');

    expect(Role::where('name', 'clerk')->exists())->toBeFalse();
});

it('never grants the permission that grants permissions', function () {
    roleAdministrator();

    $this->post(route('app.settings.roles.store'), [
        'name' => 'deputy admin',
        'permissions' => [PermissionEnum::RolesManage->value],
    ])->assertSessionHasErrors('name');

    expect(Role::where('name', 'deputy_admin')->exists())->toBeFalse();
});

it('re-scopes an office and records that it may no longer be reseeded over', function () {
    roleAdministrator();

    $chair = Role::findByName(MemberRole::Chairperson->value);

    $this->put(route('app.settings.roles.update', $chair), [
        'permissions' => [PermissionEnum::ReportsView->value],
    ])->assertRedirect();

    $chair->refresh();

    expect($chair->permissions->pluck('name')->all())->toBe([PermissionEnum::ReportsView->value])
        ->and($chair->permissions_customised_at)->not->toBeNull()
        ->and($chair->hasPermissionTo(PermissionEnum::LoansApprove->value))->toBeFalse();
});

it('keeps a re-scoped office through a reseed', function () {
    roleAdministrator();

    $treasurer = Role::findByName(MemberRole::Treasurer->value);

    $this->put(route('app.settings.roles.update', $treasurer), [
        'permissions' => [PermissionEnum::ReportsView->value],
    ]);

    $this->seed(RoleSeeder::class);

    expect(Role::findByName(MemberRole::Treasurer->value)->permissions->pluck('name')->all())
        ->toBe([PermissionEnum::ReportsView->value]);
});

it('puts a re-scoped office back on the constitution', function () {
    roleAdministrator();

    $treasurer = Role::findByName(MemberRole::Treasurer->value);

    $this->put(route('app.settings.roles.update', $treasurer), [
        'permissions' => [PermissionEnum::ReportsView->value],
    ]);

    $this->post(route('app.settings.roles.reset', $treasurer))->assertRedirect();

    $treasurer->refresh();

    expect($treasurer->permissions_customised_at)->toBeNull()
        ->and($treasurer->hasPermissionTo(PermissionEnum::LoansDisburse->value))->toBeTrue();
});

it('never renames an office, because committee terms grant it by name', function () {
    roleAdministrator();

    $treasurer = Role::findByName(MemberRole::Treasurer->value);

    $this->put(route('app.settings.roles.update', $treasurer), [
        'name' => 'money person',
        'permissions' => [PermissionEnum::SavingsRecord->value],
    ])->assertRedirect();

    expect(Role::where('name', MemberRole::Treasurer->value)->exists())->toBeTrue()
        ->and(Role::where('name', 'money_person')->exists())->toBeFalse();
});

it('never lets the administrator bundle be trimmed', function () {
    roleAdministrator();

    $admin = Role::findByName(MemberRole::Admin->value);

    $this->put(route('app.settings.roles.update', $admin), [
        'permissions' => [PermissionEnum::ReportsView->value],
    ])->assertSessionHasErrors('permissions');

    expect($admin->fresh()->permissions)->toHaveCount(count(PermissionEnum::cases()));
});

it('never deletes an office', function () {
    roleAdministrator();

    $chair = Role::findByName(MemberRole::Chairperson->value);

    $this->delete(route('app.settings.roles.destroy', $chair))->assertRedirect();

    expect(Role::where('name', MemberRole::Chairperson->value)->exists())->toBeTrue();
});

it('deletes a role of its own and takes it off everyone holding it', function () {
    roleAdministrator();

    $role = $this->manager->create('loans clerk', null, [PermissionEnum::LoansView->value]);

    $holder = User::factory()->create();
    $holder->assignRole($role->name);

    $this->delete(route('app.settings.roles.destroy', $role))->assertRedirect();

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Role::where('name', 'loans_clerk')->exists())->toBeFalse()
        ->and($holder->fresh()->can(PermissionEnum::LoansView->value))->toBeFalse();
});

it('keeps a role the administrator added through a reseed', function () {
    $this->manager->create('loans clerk', null, [PermissionEnum::LoansView->value]);

    $this->seed(RoleSeeder::class);

    expect(Role::where('name', 'loans_clerk')->exists())->toBeTrue();
});

it('grants a new role its permissions to the people holding it', function () {
    $role = $this->manager->create('loans clerk', null, [PermissionEnum::LoansView->value]);

    $user = User::factory()->create();
    $user->assignRole($role->name);

    expect($user->can(PermissionEnum::LoansView->value))->toBeTrue()
        ->and($user->can(PermissionEnum::LoansApprove->value))->toBeFalse();

    $this->manager->update($role, null, null, [
        PermissionEnum::LoansView->value,
        PermissionEnum::LoansApprove->value,
    ]);

    expect($user->fresh()->can(PermissionEnum::LoansApprove->value))->toBeTrue();
});

it('refuses a name with nothing in it', function () {
    expect(fn () => $this->manager->create('  !!  ', null, []))
        ->toThrow(DomainRuleException::class);
});

it('writes every change to the audit log', function () {
    $admin = roleAdministrator();

    $role = $this->manager->create('loans clerk', null, [PermissionEnum::LoansView->value], $admin);

    $this->manager->update($role, 'loans clerk', null, [PermissionEnum::LoansApprove->value], $admin);

    $entries = DB::table('activity_log')->where('log_name', 'roles')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('event')->all())->toBe(['role.created', 'role.updated']);
});

/** Only the administrator holds `roles.manage`, so every other office is shut out. */
it('is closed to every office but the administrator', function (MemberRole $role) {
    $user = User::factory()->create();
    $user->assignRole($role->value);

    $this->actingAs($user);

    $this->get(route('app.settings.roles'))->assertForbidden();
    $this->post(route('app.settings.roles.store'), ['name' => 'x', 'permissions' => []])
        ->assertForbidden();
})->with([
    'chairperson' => MemberRole::Chairperson,
    'vice-chairperson' => MemberRole::ViceChairperson,
    'treasurer' => MemberRole::Treasurer,
    'vice-treasurer' => MemberRole::ViceTreasurer,
    'member' => MemberRole::Member,
]);

it('shows the administrator the roles with what may be done to each', function () {
    roleAdministrator();

    $this->manager->create('loans clerk', 'At the table.', [PermissionEnum::LoansView->value]);

    $this->get(route('app.settings.roles'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $roles = collect($page->toArray()['props']['roles']);

            $admin = $roles->firstWhere('name', MemberRole::Admin->value);
            $clerk = $roles->firstWhere('name', 'loans_clerk');
            $chair = $roles->firstWhere('name', MemberRole::Chairperson->value);

            expect($admin['abilities']['editPermissions'])->toBeFalse()
                ->and($chair['abilities']['rename'])->toBeFalse()
                ->and($chair['abilities']['delete'])->toBeFalse()
                ->and($clerk['abilities']['delete'])->toBeTrue()
                ->and($clerk['holders'])->toBe(0)
                ->and($clerk['permissions'])->toBe([PermissionEnum::LoansView->value]);
        });
});

it('never offers roles.manage as a permission to grant', function () {
    roleAdministrator();

    $this->get(route('app.settings.roles'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $offered = collect($page->toArray()['props']['permissionGroups'])
                ->flatMap(fn (array $group): array => array_column($group['permissions'], 'name'));

            expect($offered)->not->toContain(PermissionEnum::RolesManage->value)
                ->and($offered)->toContain(PermissionEnum::LoansApprove->value);
        });
});
