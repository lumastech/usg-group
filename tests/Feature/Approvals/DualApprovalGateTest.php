<?php

use App\Domain\Approvals\DualApprovalGate;
use App\Enums\ApprovalStatus;
use App\Enums\MemberRole;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->gate = app(DualApprovalGate::class);
    $this->cycle = Cycle::factory()->create();

    $this->member = fn (?MemberRole $role = null) => tap(
        Member::factory()->for($this->cycle)->create([
            'user_id' => tap(User::factory()->create(), function (User $user) use ($role) {
                $user->assignRole(($role ?? MemberRole::Member)->value);
            })->id,
        ]),
        fn (Member $member) => $member->load('user'),
    );
});

it('requires two different committee members to confirm an action', function () {
    $requester = ($this->member)(MemberRole::Treasurer);
    $chair = ($this->member)(MemberRole::Chairperson);
    $viceChair = ($this->member)(MemberRole::ViceChairperson);

    $approval = $this->gate->request($this->cycle, 'loan.approve', $requester);
    expect($approval->status)->toBe(ApprovalStatus::Pending);

    $this->gate->approve($approval, $chair);
    expect($approval->fresh()->status)->toBe(ApprovalStatus::PartiallyApproved);

    $this->gate->approve($approval, $viceChair);
    expect($approval->fresh()->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->fresh()->isApproved())->toBeTrue();
});

it('refuses to let a member approve their own request', function () {
    $requester = ($this->member)(MemberRole::Treasurer);

    $approval = $this->gate->request($this->cycle, 'payout.release', $requester);

    $this->gate->approve($approval, $requester);
})->throws(DomainRuleException::class, 'cannot approve their own request');

it('refuses to count the same approver twice', function () {
    $requester = ($this->member)(MemberRole::Treasurer);
    $chair = ($this->member)(MemberRole::Chairperson);

    $approval = $this->gate->request($this->cycle, 'payout.release', $requester);
    $this->gate->approve($approval, $chair);

    $this->gate->approve($approval, $chair);
})->throws(DomainRuleException::class, 'second, different committee member');

it('refuses approval from a member who holds no committee office', function () {
    $requester = ($this->member)(MemberRole::Treasurer);
    $ordinary = ($this->member)();

    $approval = $this->gate->request($this->cycle, 'loan.approve', $requester);

    $this->gate->approve($approval, $ordinary);
})->throws(DomainRuleException::class, 'Only committee members');

it('blocks the action until both confirmations are in', function () {
    $requester = ($this->member)(MemberRole::Treasurer);
    $chair = ($this->member)(MemberRole::Chairperson);
    $viceChair = ($this->member)(MemberRole::ViceChairperson);

    $approval = $this->gate->request($this->cycle, 'loan.approve', $requester);

    expect(fn () => $this->gate->assertApproved($this->cycle, 'loan.approve'))
        ->toThrow(DomainRuleException::class, 'two committee members');

    $this->gate->approve($approval, $chair);

    expect(fn () => $this->gate->assertApproved($this->cycle, 'loan.approve'))
        ->toThrow(DomainRuleException::class);

    $this->gate->approve($approval, $viceChair);

    expect(fn () => $this->gate->assertApproved($this->cycle, 'loan.approve'))->not->toThrow(DomainRuleException::class);
});

it('cannot approve a rejected request', function () {
    $requester = ($this->member)(MemberRole::Treasurer);
    $chair = ($this->member)(MemberRole::Chairperson);
    $viceChair = ($this->member)(MemberRole::ViceChairperson);

    $approval = $this->gate->request($this->cycle, 'loan.approve', $requester);
    $this->gate->reject($approval, $chair, 'Member already has a running loan.');

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and($approval->fresh()->note)->toContain('already has a running loan');

    $this->gate->approve($approval->fresh(), $viceChair);
})->throws(DomainRuleException::class, 'has been rejected');
