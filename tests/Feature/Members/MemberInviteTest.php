<?php

use App\Domain\Members\MemberInviter;
use App\Enums\MemberRole;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use App\Notifications\MemberInvitation;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    Notification::fake();

    $this->inviter = app(MemberInviter::class);
    $this->cycle = Cycle::factory()->create();
    $this->member = Member::factory()->for($this->cycle)->create(['user_id' => null]);
});

it('creates a login, links it to the member and grants the member role', function () {
    $user = $this->inviter->invite($this->member, 'Bertha@Example.com');

    expect($user->email)->toBe('bertha@example.com')
        ->and($user->hasRole(MemberRole::Member->value))->toBeTrue()
        ->and($this->member->fresh()->user_id)->toBe($user->id);

    Notification::assertSentTo($user, MemberInvitation::class);
});

it('reuses an existing account rather than duplicating it', function () {
    $existing = User::factory()->create(['email' => 'chair@example.com']);
    $existing->assignRole(MemberRole::Chairperson->value);

    $user = $this->inviter->invite($this->member, 'chair@example.com');

    expect($user->id)->toBe($existing->id)
        ->and(User::where('email', 'chair@example.com')->count())->toBe(1)
        ->and($user->hasRole(MemberRole::Chairperson->value))->toBeTrue()
        ->and($user->hasRole(MemberRole::Member->value))->toBeTrue();
});

it('refuses to link one login to two members in the same cycle', function () {
    $other = Member::factory()->for($this->cycle)->create();
    $this->inviter->invite($other, 'shared@example.com');

    $this->inviter->invite($this->member, 'shared@example.com');
})->throws(DomainRuleException::class, 'already linked to another member');

it('sends an activation link the member can set a password with', function () {
    $user = $this->inviter->invite($this->member, 'bertha@example.com');

    Notification::assertSentTo($user, MemberInvitation::class, function (MemberInvitation $notification) use ($user) {
        $payload = $notification->toArray($user);

        return str_contains($payload['activation_url'], route('password.reset', ['token' => $notification->token]))
            && $payload['member_id'] === $this->member->id;
    });
});

it('lets an office holder invite a member from the register', function () {
    $manager = User::factory()->create();
    $manager->assignRole(MemberRole::Chairperson->value);

    $this->actingAs($manager)
        ->post(route('app.members.invite', $this->member), ['email' => 'new.member@example.com'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->member->fresh()->user_id)->not->toBeNull();
});

it('does not offer to invite a member who already has a login', function () {
    $manager = User::factory()->create();
    $manager->assignRole(MemberRole::Chairperson->value);

    $linked = Member::factory()->for($this->cycle)->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($manager)
        ->post(route('app.members.invite', $linked), ['email' => 'someone@example.com'])
        ->assertForbidden();
});
