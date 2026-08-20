<?php

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * The register as the committee actually drives it, over HTTP.
 *
 * The cycle runs Dec 2025 – Nov 2026, so these travel to a date inside the
 * registration window; a test that does not travel is testing the closed state.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();

    $this->chair = User::factory()->create();
    $this->chair->assignRole(MemberRole::Chairperson->value);
});

/** @return array<string, mixed> */
function memberPayload(array $overrides = []): array
{
    return array_merge([
        'full_name' => 'Chanda Mwale',
        'nrc_number' => '123456/78/9',
        'phone' => '0977000111',
        'physical_address' => 'Chalala, Lusaka',
        'is_diaspora' => false,
        'joined_on' => '2026-01-10',
        'joining_fee_ngwee' => 100_000,
        'joining_fee_paid' => true,
        'next_of_kin' => [
            ['name' => 'Mary Mwale', 'phone' => '0966000111', 'relationship' => 'sibling', 'relationship_label' => 'Sister'],
        ],
    ], $overrides);
}

it('lists the register with each row carrying its own abilities', function () {
    Member::factory()->for($this->cycle)->count(3)->create();

    $this->actingAs($this->chair)
        ->get(route('app.members.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/members/Index')
            ->has('members.data', 3)
            ->where('members.data.0.abilities.update', true)
            ->has('registration')
        );
});

it('filters the register by status and by diaspora', function () {
    Member::factory()->for($this->cycle)->create(['full_name' => 'Active Local']);
    Member::factory()->for($this->cycle)->diaspora()->create(['full_name' => 'Active Abroad']);
    Member::factory()->for($this->cycle)->leftEarly()->create(['full_name' => 'Gone']);

    $this->actingAs($this->chair)
        ->get(route('app.members.index', ['status' => MemberStatus::LeftEarly->value]))
        ->assertInertia(fn ($page) => $page->has('members.data', 1)->where('members.data.0.full_name', 'Gone'));

    $this->actingAs($this->chair)
        ->get(route('app.members.index', ['diaspora' => 'true']))
        ->assertInertia(fn ($page) => $page->has('members.data', 1)->where('members.data.0.full_name', 'Active Abroad'));
});

it('searches by name, NRC and phone', function () {
    Member::factory()->for($this->cycle)->create(['full_name' => 'Bertha Chileshe', 'nrc_number' => '155110/10/1']);
    Member::factory()->for($this->cycle)->create(['full_name' => 'Gift Kunda', 'nrc_number' => '172852/18/1']);

    $this->actingAs($this->chair)
        ->get(route('app.members.index', ['search' => '155110']))
        ->assertInertia(fn ($page) => $page->has('members.data', 1)->where('members.data.0.full_name', 'Bertha Chileshe'));
});

it('registers a member with their next of kin', function () {
    $this->travelTo(Carbon::parse('2026-01-10'));

    $this->actingAs($this->chair)
        ->post(route('app.members.store'), memberPayload())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $member = Member::firstWhere('full_name', 'Chanda Mwale');

    expect($member->member_number)->toBe(1)
        ->and($member->joining_month_sequence)->toBe(2)
        ->and($member->status)->toBe(MemberStatus::Active)
        ->and($member->nextOfKin)->toHaveCount(1)
        ->and($member->nextOfKin->first()->relationship_label)->toBe('Sister');
});

it('rejects an NRC that is not in the national format', function (string $nrc) {
    $this->travelTo(Carbon::parse('2026-01-10'));

    $this->actingAs($this->chair)
        ->post(route('app.members.store'), memberPayload(['nrc_number' => $nrc]))
        ->assertSessionHasErrors('nrc_number');
})->with(['12345/78/9', '123456/7/9', '123456789', '123456/78/', 'abcdef/78/9']);

it('rejects an NRC already registered in the cycle', function () {
    $this->travelTo(Carbon::parse('2026-01-10'));
    Member::factory()->for($this->cycle)->create(['nrc_number' => '123456/78/9']);

    $this->actingAs($this->chair)
        ->post(route('app.members.store'), memberPayload())
        ->assertSessionHasErrors('nrc_number');
});

it('demands the late registration fee from anyone joining in month three', function () {
    $this->travelTo(Carbon::parse('2026-02-14'));

    $this->actingAs($this->chair)
        ->post(route('app.members.store'), memberPayload([
            'joined_on' => '2026-02-14',
            'joining_fee_ngwee' => 100_000,
        ]))
        ->assertSessionHasErrors('joining_fee_ngwee');

    $this->actingAs($this->chair)
        ->post(route('app.members.store'), memberPayload([
            'joined_on' => '2026-02-14',
            'joining_fee_ngwee' => 200_000,
        ]))
        ->assertSessionHasNoErrors();
});

it('keeps registration open on the last day of month three', function () {
    $this->travelTo(Carbon::parse('2026-02-28'));

    $this->actingAs($this->chair)
        ->post(route('app.members.store'), memberPayload([
            'joined_on' => '2026-02-28',
            'joining_fee_ngwee' => 200_000,
        ]))
        ->assertSessionHasNoErrors();

    expect(Member::count())->toBe(1);
});

it('hard-locks registration the day month three ends, for everyone', function () {
    $this->travelTo(Carbon::parse('2026-03-01'));

    $this->actingAs($this->chair)
        ->post(route('app.members.store'), memberPayload(['joined_on' => '2026-03-01']))
        ->assertForbidden();

    $admin = User::factory()->create();
    $admin->assignRole(MemberRole::Admin->value);

    $this->actingAs($admin)
        ->post(route('app.members.store'), memberPayload(['joined_on' => '2026-03-01']))
        ->assertForbidden();

    expect(Member::count())->toBe(0);
});

it('shows the create page as a locked explanation once registration has closed', function () {
    $this->travelTo(Carbon::parse('2026-03-01'));

    $this->actingAs($this->chair)
        ->get(route('app.members.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/members/Create')
            ->where('canCreate', false)
            ->where('registration.open', false)
        );
});

it('corrects a member without touching how they joined', function () {
    $member = Member::factory()->for($this->cycle)->withNextOfKin()->create([
        'full_name' => 'Mispelt Name',
        'nrc_number' => '123456/78/9',
    ]);

    $this->travelTo(Carbon::parse('2026-06-01'));

    $this->actingAs($this->chair)
        ->put(route('app.members.update', $member), [
            'full_name' => 'Corrected Name',
            'nrc_number' => '123456/78/9',
            'phone' => '0955123456',
            'physical_address' => 'Kabulonga',
            'is_diaspora' => true,
            'joining_fee_paid' => true,
            'next_of_kin' => [['name' => 'New Nominee', 'relationship' => 'spouse']],
        ])
        ->assertSessionHasNoErrors();

    $member->refresh();

    expect($member->full_name)->toBe('Corrected Name')
        ->and($member->is_diaspora)->toBeTrue()
        ->and($member->joining_month_sequence)->toBe(1)
        ->and($member->nextOfKin()->pluck('name')->all())->toBe(['New Nominee']);
});

it('shows a profile with its next of kin and status timeline', function () {
    $member = Member::factory()->for($this->cycle)->withNextOfKin()->create();

    $this->actingAs($this->chair)
        ->put(route('app.members.status', $member), [
            'status' => MemberStatus::LeftEarly->value,
            'reason' => 'Moved abroad.',
        ])->assertSessionHasNoErrors();

    $this->actingAs($this->chair)
        ->get(route('app.members.show', $member))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/members/Show')
            ->where('member.status', MemberStatus::LeftEarly->value)
            ->has('member.next_of_kin', 1)
            ->has('timeline')
            ->has('transitions')
        );
});

it('refuses a status change the member cannot make', function () {
    $member = Member::factory()->for($this->cycle)->deceased()->create();

    $this->actingAs($this->chair)
        ->put(route('app.members.status', $member), ['status' => MemberStatus::Active->value])
        ->assertForbidden();
});

it('requires a ground when expelling and a date when recording a death', function () {
    $member = Member::factory()->for($this->cycle)->create();

    $this->actingAs($this->chair)
        ->put(route('app.members.status', $member), ['status' => MemberStatus::Expelled->value])
        ->assertSessionHasErrors('expulsion_ground');

    $this->actingAs($this->chair)
        ->put(route('app.members.status', $member), ['status' => MemberStatus::Deceased->value])
        ->assertSessionHasErrors('date_of_death');
});
