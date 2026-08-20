<?php

use App\Enums\MemberRole;
use App\Enums\Permission as PermissionEnum;
use Database\Seeders\RoleSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Roles are bundles; permissions are what the application actually checks. These
 * tests pin the bundles so a permission cannot quietly disappear from an office.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('creates every permission', function () {
    expect(Permission::count())->toBe(count(PermissionEnum::cases()));

    foreach (PermissionEnum::values() as $permission) {
        expect(Permission::where('name', $permission)->exists())->toBeTrue();
    }
});

it('creates every role', function () {
    expect(Role::count())->toBe(count(MemberRole::cases()));

    foreach (MemberRole::values() as $role) {
        expect(Role::where('name', $role)->exists())->toBeTrue();
    }
});

it('gives the admin every permission', function () {
    $admin = Role::findByName(MemberRole::Admin->value);

    expect($admin->permissions)->toHaveCount(count(PermissionEnum::cases()));
});

it('lets the chairperson approve loans but never disburse them', function () {
    $chair = Role::findByName(MemberRole::Chairperson->value);

    // Approval and disbursement are deliberately split between the offices.
    expect($chair->hasPermissionTo(PermissionEnum::LoansApprove->value))->toBeTrue()
        ->and($chair->hasPermissionTo(PermissionEnum::LoansDisburse->value))->toBeFalse();
});

it('lets the treasurer disburse loans but never approve them', function () {
    $treasurer = Role::findByName(MemberRole::Treasurer->value);

    expect($treasurer->hasPermissionTo(PermissionEnum::LoansDisburse->value))->toBeTrue()
        ->and($treasurer->hasPermissionTo(PermissionEnum::LoansApprove->value))->toBeFalse();
});

it('gives an ordinary member only their own declaration and their own loan request', function () {
    $member = Role::findByName(MemberRole::Member->value);

    expect($member->permissions->pluck('name')->all())
        ->toBe([PermissionEnum::DeclarationsSubmitOwn->value, PermissionEnum::LoansRequest->value]);
});

it('never lets an ordinary member touch money or members', function () {
    $member = Role::findByName(MemberRole::Member->value);

    foreach ([
        PermissionEnum::MembersManage,
        PermissionEnum::SavingsRecord,
        PermissionEnum::LoansApprove,
        PermissionEnum::LoansDisburse,
        PermissionEnum::PayoutsExecute,
        PermissionEnum::FundApproveOutflow,
    ] as $forbidden) {
        expect($member->hasPermissionTo($forbidden->value))->toBeFalse();
    }
});

it('is safe to run twice', function () {
    $this->seed(RoleSeeder::class);

    expect(Role::count())->toBe(count(MemberRole::cases()))
        ->and(Permission::count())->toBe(count(PermissionEnum::cases()));
});

it('revokes a permission that no longer belongs to a role', function () {
    $member = Role::findByName(MemberRole::Member->value);
    $member->givePermissionTo(PermissionEnum::PayoutsExecute->value);

    $this->seed(RoleSeeder::class);

    expect($member->fresh()->hasPermissionTo(PermissionEnum::PayoutsExecute->value))->toBeFalse();
});

it('removes a role that is no longer an office', function () {
    // A rename leaves the old row behind; it must not linger with live grants.
    Role::findOrCreate('Vice-Treasurer', 'web')
        ->givePermissionTo(PermissionEnum::PayoutsExecute->value);

    $this->seed(RoleSeeder::class);

    expect(Role::where('name', 'Vice-Treasurer')->exists())->toBeFalse()
        ->and(Role::count())->toBe(count(MemberRole::cases()));
});

it('treats committee roles as committee and members as not', function () {
    expect(MemberRole::Chairperson->isCommittee())->toBeTrue()
        ->and(MemberRole::Treasurer->isCommittee())->toBeTrue()
        ->and(MemberRole::Admin->isCommittee())->toBeTrue()
        ->and(MemberRole::Member->isCommittee())->toBeFalse();
});
