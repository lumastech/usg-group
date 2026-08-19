<?php

use App\Enums\MemberRole;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
});

it('creates an administrator holding the admin role', function () {
    config(['unity.admin.email' => 'admin@admin.com', 'unity.admin.password' => 'known-password']);

    $this->seed(AdminSeeder::class);

    $admin = User::where('email', 'admin@admin.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->hasRole(MemberRole::Admin->value))->toBeTrue()
        ->and(Hash::check('known-password', $admin->password))->toBeTrue();
});

it('does not make the administrator a member of the group', function () {
    config(['unity.admin.password' => 'known-password']);

    $this->seed(AdminSeeder::class);

    $admin = User::where('email', config('unity.admin.email'))->first();

    expect(Member::where('user_id', $admin->id)->exists())->toBeFalse();
});

it('leaves the administrator without any committee approval power', function () {
    config(['unity.admin.password' => 'known-password']);

    $this->seed(AdminSeeder::class);

    $admin = User::where('email', config('unity.admin.email'))->first();

    foreach (MemberRole::committee() as $office) {
        expect($admin->hasRole($office->value))->toBeFalse();
    }
});

it('generates a random password when none is configured', function () {
    config(['unity.admin.password' => null]);

    $this->seed(AdminSeeder::class);

    $admin = User::where('email', config('unity.admin.email'))->first();

    expect($admin)->not->toBeNull()
        ->and(Hash::check('password', $admin->password))->toBeFalse();
});

it('can be re-run without duplicating the account or resetting the password', function () {
    config(['unity.admin.password' => 'first-password']);

    $this->seed(AdminSeeder::class);

    config(['unity.admin.password' => 'second-password']);

    $this->seed(AdminSeeder::class);

    $admins = User::where('email', config('unity.admin.email'))->get();

    expect($admins)->toHaveCount(1)
        ->and(Hash::check('first-password', $admins->first()->password))->toBeTrue();
});

it('restores the admin role if it was removed', function () {
    config(['unity.admin.password' => 'known-password']);

    $this->seed(AdminSeeder::class);

    $admin = User::where('email', config('unity.admin.email'))->first();
    $admin->syncRoles([]);

    $this->seed(AdminSeeder::class);

    expect($admin->fresh()->hasRole(MemberRole::Admin->value))->toBeTrue();
});
