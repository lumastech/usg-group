<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\DefaultWorkflowService;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Savings\SavingsLedger;
use App\Enums\CollateralClaimStatus;
use App\Enums\LoanStatus;
use App\Enums\MemberRole;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->workflow = app(DefaultWorkflowService::class);
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->borrower = memberWithRole($this->cycle);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->viceChair = memberWithRole($this->cycle, MemberRole::ViceChairperson);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->plainMember = memberWithRole($this->cycle);

    app(SavingsLedger::class)->record(
        $this->borrower,
        $this->months->firstWhere('sequence', 1),
        Kwacha::of(5_000),
        $this->treasurer,
    );

    $loan = app(LoanApplicationService::class)->request(
        $this->borrower,
        Kwacha::of(2_000),
        $this->treasurer,
        Carbon::parse('2026-01-02 09:00'),
    );

    app(LoanApplicationService::class)->approve($loan, $this->chair, $this->treasurer);

    $this->loan = app(LoanDisbursementQueue::class)->disburse(
        $loan->refresh(),
        $this->months->firstWhere('sequence', 2),
        $this->treasurer,
    );

    $this->goods = [
        ['description' => 'Deep freezer', 'estimated_value_ngwee' => 150_000],
        ['description' => 'Living room suite', 'estimated_value_ngwee' => 80_000],
    ];
});

it('declares a loan in default', function () {
    $loan = $this->workflow->markDefaulted($this->loan, $this->chair, 'Three months without contact.');

    expect($loan->status)->toBe(LoanStatus::Defaulted)
        ->and($loan->defaulted_at)->not->toBeNull();
});

it('will not declare a loan that has not been disbursed', function () {
    $this->loan->forceFill(['status' => LoanStatus::Settled])->save();

    $this->workflow->markDefaulted($this->loan, $this->chair, 'Nothing to recover.');
})->throws(DomainRuleException::class, 'Only a loan being repaid');

it('drafts an itemised claim against a defaulted loan', function () {
    $this->workflow->markDefaulted($this->loan, $this->chair, 'No contact.');

    $claim = $this->workflow->openClaim($this->loan->refresh(), $this->goods, $this->chair);

    expect($claim->status)->toBe(CollateralClaimStatus::Draft)
        ->and($claim->items)->toHaveCount(2)
        ->and($claim->claimed_value_ngwee->getMinorAmount()->toInt())->toBe(230_000)
        ->and($claim->outstanding_at_claim_ngwee->getMinorAmount()->toInt())->toBe(200_000)
        ->and($claim->coversOutstanding())->toBeTrue();
});

it('refuses a claim that does not reach the outstanding value', function () {
    $this->workflow->markDefaulted($this->loan, $this->chair, 'No contact.');

    $this->workflow->openClaim(
        $this->loan->refresh(),
        [['description' => 'Radio', 'estimated_value_ngwee' => 20_000]],
        $this->chair,
    );
})->throws(DomainRuleException::class, 'short of the K2,000.00 still owed');

it('refuses a claim with nothing itemised on it', function () {
    $this->workflow->markDefaulted($this->loan, $this->chair, 'No contact.');

    $this->workflow->openClaim($this->loan->refresh(), [], $this->chair);
})->throws(DomainRuleException::class, 'must itemise the goods');

it('refuses a claim against a loan that is not in default', function () {
    $this->workflow->openClaim($this->loan, $this->goods, $this->chair);
})->throws(DomainRuleException::class, 'only be raised against a loan in default');

it('needs a second committee signature before it can be enforced', function () {
    $this->workflow->markDefaulted($this->loan, $this->chair, 'No contact.');
    $claim = $this->workflow->openClaim($this->loan->refresh(), $this->goods, $this->chair);

    $this->workflow->enforce($claim, $this->chair);
})->throws(DomainRuleException::class, 'two committee signatures');

it('takes a signed claim through to enforcement', function () {
    $this->workflow->markDefaulted($this->loan, $this->chair, 'No contact.');
    $claim = $this->workflow->openClaim($this->loan->refresh(), $this->goods, $this->chair);

    $signed = $this->workflow->signOff($claim, $this->viceChair);

    expect($signed->status)->toBe(CollateralClaimStatus::CommitteeSignOff)
        ->and($signed->second_signer_member_id)->toBe($this->viceChair->id);

    $enforced = $this->workflow->enforce($signed, $this->chair);

    expect($enforced->status)->toBe(CollateralClaimStatus::Enforced)
        ->and($enforced->enforced_at)->not->toBeNull();
});

it('refuses a sign-off from the same person who drafted the claim', function () {
    $this->workflow->markDefaulted($this->loan, $this->chair, 'No contact.');
    $claim = $this->workflow->openClaim($this->loan->refresh(), $this->goods, $this->chair);

    $this->workflow->signOff($claim, $this->chair);
})->throws(DomainRuleException::class, 'second, different committee member');

it('refuses a sign-off from someone off the committee', function () {
    $this->workflow->markDefaulted($this->loan, $this->chair, 'No contact.');
    $claim = $this->workflow->openClaim($this->loan->refresh(), $this->goods, $this->chair);

    $this->workflow->signOff($claim, $this->plainMember);
})->throws(DomainRuleException::class, 'does not sit on the committee');

it('releases a claim when the member settles after all', function () {
    $this->workflow->markDefaulted($this->loan, $this->chair, 'No contact.');
    $claim = $this->workflow->openClaim($this->loan->refresh(), $this->goods, $this->chair);

    $released = $this->workflow->release($claim, $this->chair, 'Paid in full on 12 August.');

    expect($released->status)->toBe(CollateralClaimStatus::Released)
        ->and($released->released_at)->not->toBeNull();
});
