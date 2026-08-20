<?php

use App\Domain\Governance\CommitteeTermService;
use App\Domain\Governance\SuccessionPlanner;
use App\Enums\CommitteeRole;
use App\Enums\MemberStatus;
use App\Models\CommitteeTerm;
use App\Models\Cycle;
use App\Models\Member;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create(['starts_on' => '2025-12-01']);
    $this->planner = app(SuccessionPlanner::class);
    $this->terms = app(CommitteeTermService::class);

    $this->serve = function (CommitteeRole $role): Member {
        $member = Member::factory()->for($this->cycle)->create();
        $this->terms->appoint($member, $role, $this->cycle, Carbon::parse('2025-12-01'));

        return $member;
    };
});

it('proposes the deputies stepping up, and appoints nobody', function () {
    $chair = ($this->serve)(CommitteeRole::Chairperson);
    $viceChair = ($this->serve)(CommitteeRole::ViceChairperson);
    $treasurer = ($this->serve)(CommitteeRole::Treasurer);
    $viceTreasurer = ($this->serve)(CommitteeRole::ViceTreasurer);

    $proposal = collect($this->planner->proposeFor($this->cycle))->keyBy('role');

    expect($proposal['chairperson'])->toMatchArray([
        'incumbent_member_id' => $chair->id,
        'proposed_member_id' => $viceChair->id,
        'source_role' => 'vice_chairperson',
        'needs_nomination' => false,
    ]);

    expect($proposal['treasurer'])->toMatchArray([
        'incumbent_member_id' => $treasurer->id,
        'proposed_member_id' => $viceTreasurer->id,
        'source_role' => 'vice_treasurer',
    ]);

    /* The seats the deputies vacate are left for the group to nominate into. */
    expect($proposal['vice_chairperson']['needs_nomination'])->toBeTrue()
        ->and($proposal['vice_treasurer']['needs_nomination'])->toBeTrue();

    /* And nothing was written: the terms on record are unchanged. */
    expect(CommitteeTerm::query()->forCycle($this->cycle->id)->current()->count())->toBe(4);
});

it('will not move up a deputy who is no longer active', function () {
    ($this->serve)(CommitteeRole::Chairperson);
    $viceChair = ($this->serve)(CommitteeRole::ViceChairperson);

    $viceChair->forceFill(['status' => MemberStatus::LeftEarly])->save();

    $proposal = collect($this->planner->proposeFor($this->cycle))->keyBy('role');

    expect($proposal['chairperson'])->toMatchArray([
        'proposed_member_id' => null,
        'needs_nomination' => true,
    ])->and($proposal['chairperson']['rationale'])->toContain('nominated into');
});

it('proposes for every office even when the committee is empty', function () {
    $proposal = $this->planner->proposeFor($this->cycle);

    expect($proposal)->toHaveCount(4)
        ->and(collect($proposal)->every(fn (array $row): bool => $row['needs_nomination']))->toBeTrue();
});

it('leaves signatories out of the succession', function () {
    ($this->serve)(CommitteeRole::Signatory);

    expect(collect($this->planner->proposeFor($this->cycle))->pluck('role')->all())
        ->not->toContain('signatory');
});
