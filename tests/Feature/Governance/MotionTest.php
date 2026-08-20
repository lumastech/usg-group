<?php

use App\Domain\Governance\CommitteeTermService;
use App\Domain\Governance\MeetingRegister;
use App\Domain\Governance\MotionRecorder;
use App\Enums\CommitteeRole;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\MotionType;
use App\Enums\TermEndReason;
use App\Enums\ThresholdBasis;
use App\Exceptions\AmendmentWindowClosedException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\MotionAlreadyDecidedException;
use App\Exceptions\QuorumNotMetException;
use App\Models\Cycle;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create(['starts_on' => '2025-12-01']);
    $this->motions = app(MotionRecorder::class);
    $this->register = app(MeetingRegister::class);
    $this->terms = app(CommitteeTermService::class);

    /** The group as constituted: thirty active members. */
    $this->members = Member::factory()->count(30)->for($this->cycle)->create();
    $this->chair = $this->members->first();

    $this->meeting = Meeting::factory()->for($this->cycle)->create(['meeting_date' => '2026-03-07']);

    /** Marks the first n members of the roll present. */
    $this->attend = function (int $count): void {
        $this->members->take($count)->each(
            fn (Member $member) => $this->register->mark($this->meeting, $member, true),
        );
    };
});

/*
|--------------------------------------------------------------------------
| Quorum
|--------------------------------------------------------------------------
*/

it('needs 60% of the active membership present for quorum', function () {
    ($this->attend)(17);

    expect($this->register->quorum($this->meeting))
        ->toMatchArray(['present' => 17, 'active' => 30, 'needed' => 18, 'met' => false, 'shortfall' => 1]);

    ($this->attend)(18);

    expect($this->register->hasQuorum($this->meeting))->toBeTrue();
});

it('rounds the quorum requirement up when the membership is awkward', function () {
    /* One member dies; 60% of 29 is 17.4, so 18 heads are still needed. */
    $this->members->last()->forceFill(['status' => MemberStatus::Deceased])->save();

    ($this->attend)(17);

    expect($this->register->quorum($this->meeting))
        ->toMatchArray(['present' => 17, 'active' => 29, 'needed' => 18, 'met' => false]);
});

it('does not count a member who has left towards either side of quorum', function () {
    ($this->attend)(18);

    $this->members->first()->forceFill(['status' => MemberStatus::LeftEarly])->save();

    expect($this->register->quorum($this->meeting))
        ->toMatchArray(['present' => 17, 'active' => 29, 'needed' => 18, 'met' => false]);
});

it('takes the register idempotently', function () {
    $member = $this->members->first();

    $this->register->mark($this->meeting, $member, true);
    $this->register->mark($this->meeting, $member, true);

    expect($this->register->presentCount($this->meeting))->toBe(1);

    expect($this->register->toggle($this->meeting, $member))->toBeFalse()
        ->and($this->register->presentCount($this->meeting))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The quorum gate
|--------------------------------------------------------------------------
*/

it('refuses to decide any motion in a meeting without quorum', function () {
    ($this->attend)(17);

    $motion = $this->motions->propose(MotionType::General, 'Buy a cash box', $this->chair, $this->meeting);

    expect(fn () => $this->motions->decide($motion, 17, 0))
        ->toThrow(QuorumNotMetException::class);

    expect($motion->refresh()->isDecided())->toBeFalse();
});

it('explains the quorum block on the motion itself', function () {
    ($this->attend)(17);

    $motion = $this->motions->propose(MotionType::General, 'Buy a cash box', $this->chair, $this->meeting);

    expect($this->motions->requirement($motion))
        ->toMatchArray(['quorum_met' => false, 'can_decide' => false])
        ->and($this->motions->requirement($motion)['blocked_reason'])
        ->toContain('has not reached quorum');
});

/*
|--------------------------------------------------------------------------
| No confidence — 60% of the WHOLE membership
|--------------------------------------------------------------------------
*/

it('measures a no-confidence motion against the total membership, not the room', function () {
    ($this->attend)(20);

    $target = $this->members[1];
    $motion = $this->motions->propose(
        MotionType::NoConfidence,
        'No confidence in the treasurer',
        $this->chair,
        $this->meeting,
        $target,
    );

    /* 17 of the 20 in the room is 85% of those present — and still not enough. */
    $decided = $this->motions->decide($motion, 17, 3);

    expect($decided->threshold_basis)->toBe(ThresholdBasis::TotalMembers)
        ->and($decided->eligible_count)->toBe(30)
        ->and($decided->votes_needed)->toBe(18)
        ->and($decided->passed)->toBeFalse()
        ->and($decided->thresholdExplanation())->toBe('needs 18 of 30 total active members');
});

it('ends the officer\'s terms as Removed when no confidence carries', function () {
    $target = ($this->members[1]);
    $target->forceFill(['user_id' => User::factory()->create()->id])->save();

    $term = $this->terms->appoint($target->refresh()->load('user'), CommitteeRole::Treasurer, $this->cycle, Carbon::parse('2025-12-01'));

    expect($target->user->refresh()->hasRole(MemberRole::Treasurer->value))->toBeTrue();

    ($this->attend)(25);

    $motion = $this->motions->propose(
        MotionType::NoConfidence,
        'No confidence in the treasurer',
        $this->chair,
        $this->meeting,
        $target,
    );

    $decided = $this->motions->decide($motion, 20, 5, at: Carbon::parse('2026-03-07 14:00'));

    expect($decided->passed)->toBeTrue()
        ->and($term->refresh()->end_reason)->toBe(TermEndReason::Removed)
        ->and($term->ended_at->toDateString())->toBe('2026-03-07')
        ->and($target->user->refresh()->hasRole(MemberRole::Treasurer->value))->toBeFalse();
});

it('allows a no-confidence motion to be raised without a meeting', function () {
    $motion = $this->motions->propose(
        MotionType::NoConfidence,
        'No confidence in the chairperson',
        $this->chair,
        target: $this->members[2],
    );

    $decided = $this->motions->decide($motion, 18, 4);

    expect($motion->meeting_id)->toBeNull()
        ->and($decided->eligible_count)->toBe(30)
        ->and($decided->passed)->toBeTrue();
});

it('refuses any other motion raised outside a meeting', function () {
    expect(fn () => $this->motions->propose(MotionType::General, 'Buy a cash box', $this->chair))
        ->toThrow(DomainRuleException::class);
});

it('insists a no-confidence motion names its officer', function () {
    expect(fn () => $this->motions->propose(MotionType::NoConfidence, 'No confidence', $this->chair, $this->meeting))
        ->toThrow(DomainRuleException::class);
});

/*
|--------------------------------------------------------------------------
| Amendments — 60% of those PRESENT
|--------------------------------------------------------------------------
*/

it('measures an amendment against the members present, not the whole group', function () {
    ($this->attend)(20);

    $motion = $this->motions->propose(
        MotionType::Amendment,
        'Raise the minimum saving',
        $this->chair,
        $this->meeting,
        amendment: [
            'section_reference' => 'Section 4.2',
            'current_text' => 'K500 minimum',
            'proposed_text' => 'K600 minimum',
            'effective_date' => '2026-07-01',
        ],
        at: Carbon::parse('2026-06-01'),
    );

    /* 12 of 20 present carries, though the same tally would fail a no-confidence vote. */
    $decided = $this->motions->decide($motion, 12, 8);

    expect($decided->threshold_basis)->toBe(ThresholdBasis::MembersPresent)
        ->and($decided->eligible_count)->toBe(20)
        ->and($decided->votes_needed)->toBe(12)
        ->and($decided->passed)->toBeTrue()
        ->and($decided->thresholdExplanation())->toBe('needs 12 of 20 members present')
        ->and($decided->amendment->section_reference)->toBe('Section 4.2');
});

/*
|--------------------------------------------------------------------------
| Recording the tally
|--------------------------------------------------------------------------
*/

it('freezes the base a motion was decided against', function () {
    ($this->attend)(20);

    $motion = $this->motions->propose(MotionType::General, 'Buy a cash box', $this->chair, $this->meeting);
    $decided = $this->motions->decide($motion, 12, 8);

    expect($decided->passed)->toBeTrue();

    /* Five more members join afterwards; the minutes must not move. */
    Member::factory()->count(5)->for($this->cycle)->create();
    $this->members->take(5)->each(fn (Member $m) => $this->register->mark($this->meeting, $m, false));

    expect($decided->refresh())
        ->eligible_count->toBe(20)
        ->votes_needed->toBe(12)
        ->passed->toBeTrue();
});

it('decides a motion once and only once', function () {
    ($this->attend)(20);

    $motion = $this->motions->propose(MotionType::General, 'Buy a cash box', $this->chair, $this->meeting);
    $this->motions->decide($motion, 12, 8);

    expect(fn () => $this->motions->decide($motion->refresh(), 20, 0))
        ->toThrow(MotionAlreadyDecidedException::class);
});

it('refuses more hands than there are people in the room', function () {
    ($this->attend)(20);

    $motion = $this->motions->propose(MotionType::General, 'Buy a cash box', $this->chair, $this->meeting);

    expect(fn () => $this->motions->decide($motion, 15, 5, 3))
        ->toThrow(DomainRuleException::class);
});

it('keeps abstentions out of the majority', function () {
    ($this->attend)(20);

    $motion = $this->motions->propose(MotionType::General, 'Buy a cash box', $this->chair, $this->meeting);

    /* 11 for, 11 abstaining: a clear plurality of those voting, and still short of 12. */
    $decided = $this->motions->decide($motion, 11, 0, 9);

    expect($decided->passed)->toBeFalse()
        ->and($decided->votesCast())->toBe(11);
});

/*
|--------------------------------------------------------------------------
| The six-month spacing rule
|--------------------------------------------------------------------------
*/

it('will not accept an amendment inside six months of the cycle starting', function () {
    ($this->attend)(20);

    expect(fn () => $this->motions->propose(
        MotionType::Amendment,
        'Raise the minimum saving',
        $this->chair,
        $this->meeting,
        amendment: [
            'section_reference' => 'Section 4.2',
            'current_text' => 'K500',
            'proposed_text' => 'K600',
            'effective_date' => '2026-07-01',
        ],
        at: Carbon::parse('2026-05-31'),
    ))->toThrow(AmendmentWindowClosedException::class);
});
