<?php

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Governance\CommitteeTermService;
use App\Domain\Governance\MeetingRegister;
use App\Domain\Governance\MotionRecorder;
use App\Enums\CommitteeRole;
use App\Enums\MeetingType;
use App\Enums\MemberRole;
use App\Enums\MotionType;
use App\Enums\TermEndReason;
use App\Models\CommitteeTerm;
use App\Models\Cycle;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\Motion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

/**
 * The governance section end to end: who may read it, who may record in it, and
 * whether the screens are handed the arithmetic they need to explain themselves.
 */
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo(Carbon::parse('2026-06-10 09:00'));

    $this->cycle = Cycle::factory()->create(['starts_on' => '2025-12-01']);
    app(CurrentCycle::class)->set($this->cycle);

    $this->members = Member::factory()->count(30)->for($this->cycle)->create();
    $this->meeting = Meeting::factory()->for($this->cycle)->create([
        'meeting_date' => '2026-06-10',
        'type' => MeetingType::Monthly,
    ]);
});

/** Signs in as a user holding one role, with a member record of their own. */
function governanceAs(MemberRole $role): Member
{
    $user = User::factory()->create();
    $user->assignRole($role->value);

    $member = Member::factory()->for(test()->cycle)->create(['user_id' => $user->id]);

    test()->actingAs($user);

    return $member;
}

/*
|--------------------------------------------------------------------------
| Reading
|--------------------------------------------------------------------------
*/

it('shows the committee register and the succession proposal', function () {
    $chair = governanceAs(MemberRole::Chairperson);
    $vice = $this->members->first();

    app(CommitteeTermService::class)->appoint($vice, CommitteeRole::ViceChairperson, $this->cycle, Carbon::parse('2025-12-01'));

    $this->get(route('app.governance.committee'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/governance/Committee')
            ->has('current', 1)
            ->where('succession.0.role', 'chairperson')
            ->where('succession.0.proposed_member_id', $vice->id)
            ->where('abilities.record', true),
        );
});

it('lets the treasurer read governance but not record in it', function () {
    governanceAs(MemberRole::Treasurer);

    $this->get(route('app.governance.committee'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('abilities.record', false));

    $this->post(route('app.governance.committee.store'), [
        'member_id' => $this->members->first()->id,
        'role' => CommitteeRole::Signatory->value,
        'started_at' => '2026-06-10',
    ])->assertForbidden();
});

it('keeps an ordinary member out of the governance section entirely', function () {
    governanceAs(MemberRole::Member);

    $this->get(route('app.governance.committee'))->assertForbidden();
    $this->get(route('app.governance.meetings.index'))->assertForbidden();
    $this->get(route('app.governance.amendments.index'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The register in the room
|--------------------------------------------------------------------------
*/

it('hands the meeting page the whole roll and a live quorum count', function () {
    governanceAs(MemberRole::Chairperson);

    app(MeetingRegister::class)->mark($this->meeting, $this->members->first(), true);

    $this->get(route('app.governance.meetings.show', $this->meeting))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/governance/MeetingShow')
            /* Thirty members plus the signed-in chairperson's own record. */
            ->has('roll', 31)
            ->where('quorum.present', 1)
            ->where('quorum.active', 31)
            ->where('quorum.needed', 19)
            ->where('quorum.met', false)
            /* Exactly the one member marked, wherever they fall in member-number order. */
            ->where('roll', fn (Collection $roll): bool => $roll->where('is_present', true)->count() === 1),
        );
});

it('marks a member present, and marks them away again', function () {
    governanceAs(MemberRole::Chairperson);
    $member = $this->members->first();

    $this->put(route('app.governance.meetings.attendance', [$this->meeting, $member]), ['present' => true])
        ->assertRedirect();

    expect(app(MeetingRegister::class)->presentCount($this->meeting))->toBe(1);

    $this->put(route('app.governance.meetings.attendance', [$this->meeting, $member]), ['present' => false]);

    expect(app(MeetingRegister::class)->presentCount($this->meeting))->toBe(0);
});

it('refuses the register to somebody without governance.record', function () {
    governanceAs(MemberRole::Treasurer);

    $this->put(
        route('app.governance.meetings.attendance', [$this->meeting, $this->members->first()]),
        ['present' => true],
    )->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Motions
|--------------------------------------------------------------------------
*/

it('shows the motion the threshold it has to clear before anybody votes', function () {
    governanceAs(MemberRole::Chairperson);

    $register = app(MeetingRegister::class);
    $this->members->take(25)->each(fn (Member $m) => $register->mark($this->meeting, $m, true));

    $this->post(route('app.governance.meetings.motions.store', $this->meeting), [
        'type' => MotionType::NoConfidence->value,
        'subject' => 'No confidence in the treasurer',
        'target_member_id' => $this->members->last()->id,
    ])->assertSessionHasNoErrors();

    $this->get(route('app.governance.meetings.show', $this->meeting))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('motions', 1)
            /* The whole membership, not the 25 in the room. */
            ->where('motions.0.requirement.base', 31)
            ->where('motions.0.requirement.needed', 19)
            ->where('motions.0.requirement.explanation', '19 of 31 total active members')
            ->where('motions.0.abilities.decide', true),
        );
});

it('records the show of hands and reports the arithmetic back', function () {
    governanceAs(MemberRole::Chairperson);

    $register = app(MeetingRegister::class);
    $this->members->take(25)->each(fn (Member $m) => $register->mark($this->meeting, $m, true));

    $motion = app(MotionRecorder::class)->propose(
        MotionType::General,
        'Buy a cash box',
        $this->members->first(),
        $this->meeting,
    );

    $this->post(route('app.governance.motions.decide', $motion), [
        'votes_for' => 15,
        'votes_against' => 10,
        'abstentions' => 0,
    ])->assertSessionHas('success', fn (string $message): bool => str_contains($message, 'carried')
        && str_contains($message, '15 of 25 members present'));

    expect($motion->refresh()->passed)->toBeTrue();
});

it('will not decide a motion in a meeting short of quorum', function () {
    governanceAs(MemberRole::Chairperson);

    $register = app(MeetingRegister::class);
    $this->members->take(10)->each(fn (Member $m) => $register->mark($this->meeting, $m, true));

    $motion = app(MotionRecorder::class)->propose(
        MotionType::General,
        'Buy a cash box',
        $this->members->first(),
        $this->meeting,
    );

    $this->post(route('app.governance.motions.decide', $motion), [
        'votes_for' => 10,
        'votes_against' => 0,
        'abstentions' => 0,
    ])->assertSessionHasErrors('motion');

    expect($motion->refresh()->isDecided())->toBeFalse();
});

it('refuses a second tally on a decided motion', function () {
    governanceAs(MemberRole::Chairperson);

    $register = app(MeetingRegister::class);
    $this->members->take(25)->each(fn (Member $m) => $register->mark($this->meeting, $m, true));

    $motion = app(MotionRecorder::class)->propose(
        MotionType::General,
        'Buy a cash box',
        $this->members->first(),
        $this->meeting,
    );
    app(MotionRecorder::class)->decide($motion, 15, 10);

    $this->post(route('app.governance.motions.decide', $motion), [
        'votes_for' => 25,
        'votes_against' => 0,
        'abstentions' => 0,
    ])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Terms
|--------------------------------------------------------------------------
*/

it('records a term through the portal and grants the portal role with it', function () {
    governanceAs(MemberRole::Chairperson);

    $user = User::factory()->create();
    $member = Member::factory()->for($this->cycle)->create(['user_id' => $user->id]);

    $this->post(route('app.governance.committee.store'), [
        'member_id' => $member->id,
        'role' => CommitteeRole::Treasurer->value,
        'started_at' => '2026-06-10',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->hasRole(MemberRole::Treasurer->value))->toBeTrue();
});

it('surfaces the notice rule as a form error rather than an exception', function () {
    governanceAs(MemberRole::Chairperson);

    $term = app(CommitteeTermService::class)->appoint(
        $this->members->first(),
        CommitteeRole::Treasurer,
        $this->cycle,
        Carbon::parse('2025-12-01'),
    );

    $this->delete(route('app.governance.committee.end', $term), [
        'end_reason' => TermEndReason::Resigned->value,
        'ended_at' => '2026-06-20',
        'resignation_notice_date' => '2026-06-10',
    ])->assertSessionHasErrors('term');

    expect($term->refresh()->isCurrent())->toBeTrue();
});

it('will not let a term be ended as Removed by hand', function () {
    governanceAs(MemberRole::Chairperson);

    $term = CommitteeTerm::factory()->for($this->cycle)->create([
        'member_id' => $this->members->first()->id,
    ]);

    $this->delete(route('app.governance.committee.end', $term), [
        'end_reason' => TermEndReason::Removed->value,
        'ended_at' => '2026-06-10',
    ])->assertSessionHasErrors('end_reason');
});

/*
|--------------------------------------------------------------------------
| Amendments
|--------------------------------------------------------------------------
*/

it('serves the amendment log with the six-month countdown', function () {
    governanceAs(MemberRole::Chairperson);

    $this->get(route('app.governance.amendments.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/governance/Amendments')
            ->where('window.is_open', true)
            ->where('window.opens_on', '2026-06-01')
            ->where('window.days_until_open', 0),
        );
});

it('blocks a proposal made while the window is shut, with the reason', function () {
    governanceAs(MemberRole::Chairperson);

    Motion::factory()->for($this->cycle)->type(MotionType::Amendment)->passed('2026-06-01 10:00')->create([
        'proposed_by_member_id' => $this->members->first()->id,
    ]);

    $this->post(route('app.governance.amendments.store'), [
        'meeting_id' => $this->meeting->id,
        'subject' => 'Raise the minimum saving',
        'section_reference' => 'Section 4.2',
        'current_text' => 'K500',
        'proposed_text' => 'K600',
        'effective_date' => '2026-09-01',
    ])->assertSessionHasErrors('amendment');
});

it('accepts a proposal once the window is open', function () {
    governanceAs(MemberRole::Chairperson);

    $this->post(route('app.governance.amendments.store'), [
        'meeting_id' => $this->meeting->id,
        'subject' => 'Raise the minimum saving',
        'section_reference' => 'Section 4.2',
        'current_text' => 'K500',
        'proposed_text' => 'K600',
        'effective_date' => '2026-09-01',
    ])->assertSessionHasNoErrors();

    $this->get(route('app.governance.amendments.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('amendments', 1)
            ->where('amendments.0.section_reference', 'Section 4.2')
            ->where('amendments.0.motion.is_decided', false),
        );
});
