<?php

use App\Domain\Governance\CommitteeRoleSync;
use App\Domain\Governance\CommitteeTermService;
use App\Enums\CommitteeRole;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\TermEndReason;
use App\Exceptions\CommitteeSeatTakenException;
use App\Exceptions\MemberNotActiveException;
use App\Exceptions\NoticePeriodNotServedException;
use App\Models\CommitteeTerm;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create(['starts_on' => '2025-12-01']);
    $this->terms = app(CommitteeTermService::class);

    /** A member with a portal login, so role grants have somewhere to land. */
    $this->memberWithLogin = function (array $attributes = []): Member {
        $user = User::factory()->create();

        return Member::factory()->for($this->cycle)->create($attributes + ['user_id' => $user->id])->load('user');
    };
});

it('grants the matching portal role for the duration of a term', function () {
    $member = ($this->memberWithLogin)();

    expect($member->user->hasRole(MemberRole::Treasurer->value))->toBeFalse();

    $term = $this->terms->appoint($member, CommitteeRole::Treasurer, $this->cycle, Carbon::parse('2025-12-01'));

    expect($member->user->refresh()->hasRole(MemberRole::Treasurer->value))->toBeTrue();

    $this->terms->end($term, TermEndReason::TermEnd, Carbon::parse('2026-11-30'));

    expect($member->user->refresh()->hasRole(MemberRole::Treasurer->value))->toBeFalse();
});

it('records a signatory without granting any portal role', function () {
    $member = ($this->memberWithLogin)();

    $this->terms->appoint($member, CommitteeRole::Signatory, $this->cycle);

    expect(CommitteeTerm::query()->forCycle($this->cycle->id)->current()->count())->toBe(1)
        ->and($member->user->refresh()->getRoleNames()->toArray())->toBe([]);
});

it('leaves roles a member holds for other reasons alone', function () {
    $member = ($this->memberWithLogin)();
    $member->user->assignRole(MemberRole::Admin->value);

    $term = $this->terms->appoint($member, CommitteeRole::Chairperson, $this->cycle);
    $this->terms->end($term, TermEndReason::TermEnd);

    expect($member->user->refresh()->hasRole(MemberRole::Admin->value))->toBeTrue();
});

it('records a term for a member who has no login yet', function () {
    $member = Member::factory()->for($this->cycle)->create(['user_id' => null]);

    $term = $this->terms->appoint($member, CommitteeRole::ViceChairperson, $this->cycle);

    expect($term->isCurrent())->toBeTrue();
});

it('refuses to put a member who has left into office', function () {
    $member = ($this->memberWithLogin)(['status' => MemberStatus::LeftEarly]);

    expect(fn () => $this->terms->appoint($member, CommitteeRole::Treasurer, $this->cycle))
        ->toThrow(MemberNotActiveException::class);
});

it('refuses a second holder of an executive office', function () {
    $first = ($this->memberWithLogin)();
    $second = ($this->memberWithLogin)();

    $this->terms->appoint($first, CommitteeRole::Chairperson, $this->cycle);

    expect(fn () => $this->terms->appoint($second, CommitteeRole::Chairperson, $this->cycle))
        ->toThrow(CommitteeSeatTakenException::class);
});

it('allows several signatories, and a signatory who already holds office', function () {
    $chair = ($this->memberWithLogin)();
    $other = ($this->memberWithLogin)();

    $this->terms->appoint($chair, CommitteeRole::Chairperson, $this->cycle);
    $this->terms->appoint($chair, CommitteeRole::Signatory, $this->cycle);
    $this->terms->appoint($other, CommitteeRole::Signatory, $this->cycle);

    expect(CommitteeTerm::query()->forCycle($this->cycle->id)->current()->count())->toBe(3);
});

it('refuses to put one member into two executive offices', function () {
    $member = ($this->memberWithLogin)();

    $this->terms->appoint($member, CommitteeRole::Treasurer, $this->cycle);

    expect(fn () => $this->terms->appoint($member, CommitteeRole::Chairperson, $this->cycle))
        ->toThrow(CommitteeSeatTakenException::class);
});

it('holds a resignation to a month\'s notice', function () {
    $member = ($this->memberWithLogin)();
    $term = $this->terms->appoint($member, CommitteeRole::Treasurer, $this->cycle, Carbon::parse('2025-12-01'));

    /* Notice on 10 March runs to 10 April; leaving on the 1st is short. */
    expect(fn () => $this->terms->resign($term, Carbon::parse('2026-03-10'), Carbon::parse('2026-04-01')))
        ->toThrow(NoticePeriodNotServedException::class);

    expect($term->refresh()->isCurrent())->toBeTrue()
        ->and($member->user->refresh()->hasRole(MemberRole::Treasurer->value))->toBeTrue();
});

it('ends a resignation exactly a month after notice by default', function () {
    $member = ($this->memberWithLogin)();
    $term = $this->terms->appoint($member, CommitteeRole::Treasurer, $this->cycle, Carbon::parse('2025-12-01'));

    $ended = $this->terms->resign($term, Carbon::parse('2026-03-10'));

    expect($ended->ended_at->toDateString())->toBe('2026-04-10')
        ->and($ended->end_reason)->toBe(TermEndReason::Resigned)
        ->and($ended->resignation_notice_date->toDateString())->toBe('2026-03-10')
        ->and($member->user->refresh()->hasRole(MemberRole::Treasurer->value))->toBeFalse();
});

it('crosses a month boundary correctly when notice falls at month end', function () {
    $member = ($this->memberWithLogin)();
    $term = $this->terms->appoint($member, CommitteeRole::ViceTreasurer, $this->cycle, Carbon::parse('2025-12-01'));

    /* 31 January plus a month is 28 February in 2026, not 3 March. */
    $ended = $this->terms->resign($term, Carbon::parse('2026-01-31'));

    expect($ended->ended_at->toDateString())->toBe('2026-02-28');
});

it('lets the committee waive the notice period in writing', function () {
    $member = ($this->memberWithLogin)();
    $term = $this->terms->appoint($member, CommitteeRole::Treasurer, $this->cycle, Carbon::parse('2025-12-01'));

    $ended = $this->terms->resign(
        $term,
        Carbon::parse('2026-03-10'),
        Carbon::parse('2026-03-15'),
        'Relocating to Ndola; the vice-treasurer covers until share-out.',
    );

    expect($ended->ended_at->toDateString())->toBe('2026-03-15')
        ->and($ended->notice_waiver_note)->toContain('Ndola')
        ->and($member->user->refresh()->hasRole(MemberRole::Treasurer->value))->toBeFalse();
});

it('knows when a term has run past its year', function () {
    $member = ($this->memberWithLogin)();
    $term = $this->terms->appoint($member, CommitteeRole::Chairperson, $this->cycle, Carbon::parse('2025-12-01'));

    expect($this->terms->expiresOn($term)->toDateString())->toBe('2026-12-01')
        ->and($this->terms->isOverdue($term, Carbon::parse('2026-11-30')))->toBeFalse()
        ->and($this->terms->isOverdue($term, Carbon::parse('2026-12-01')))->toBeTrue();
});

it('reconciles roles from the terms on record', function () {
    $member = ($this->memberWithLogin)();

    /* A term written straight to the table, as an import would. */
    CommitteeTerm::create([
        'cycle_id' => $this->cycle->id,
        'member_id' => $member->id,
        'role' => CommitteeRole::Chairperson,
        'started_at' => '2025-12-01',
    ]);

    /* And a role granted by hand that no term backs. */
    $member->user->assignRole(MemberRole::Treasurer->value);

    app(CommitteeRoleSync::class)->syncCycle($this->cycle);

    expect($member->user->refresh()->hasRole(MemberRole::Chairperson->value))->toBeTrue()
        ->and($member->user->hasRole(MemberRole::Treasurer->value))->toBeFalse();
});
