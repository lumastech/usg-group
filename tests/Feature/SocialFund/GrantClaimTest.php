<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\SocialFund\GrantClaimService;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\FuneralRelationship;
use App\Enums\GrantClaimStatus;
use App\Enums\MemberRole;
use App\Enums\SocialFundTransactionType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InsufficientSocialFundException;
use App\Models\Cycle;
use App\Models\FuneralGrantClaim;
use App\Models\Member;
use App\Models\UnityBabyClaim;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use ValueError;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = memberWithRole($this->cycle);

    $this->claims = app(GrantClaimService::class);
    $this->ledger = app(SocialFundLedger::class);

    /* Ten members' contributions, so the fund can carry a grant. */
    Member::factory()->count(9)->for($this->cycle)->create()
        ->each(fn (Member $payer) => app(SocialFundContributions::class)
            ->record($payer, Kwacha::of(250), $this->treasurer));

    app(SocialFundContributions::class)->record($this->member, Kwacha::of(250), $this->treasurer);
});

function funeralClaim(Cycle $cycle, Member $member, FuneralRelationship $relationship): FuneralGrantClaim
{
    return FuneralGrantClaim::factory()->for($cycle)->for($member)->create([
        'relationship' => $relationship,
        'amount_ngwee' => 100_000,
    ]);
}

/*
 * The constitution restricts the funeral grant to a parent, spouse or child and allows
 * no discretion, so a sibling claim is refused by the type system rather than by a
 * policy someone could later be persuaded to relax.
 */
it('cannot even represent a claim for a sibling', function () {
    FuneralRelationship::from('sibling');
})->throws(ValueError::class);

it('refuses to store a funeral claim for a relationship outside the three allowed', function () {
    FuneralGrantClaim::factory()->for($this->cycle)->for($this->member)->create(['relationship' => 'sibling']);
})->throws(ValueError::class);

it('pays a funeral grant of one thousand kwacha after two signatures', function () {
    $claim = funeralClaim($this->cycle, $this->member, FuneralRelationship::Parent);

    $this->claims->approve($claim, $this->chair, $this->treasurer);

    expect($claim->fresh()->status)->toBe(GrantClaimStatus::Approved)
        /* Approval alone must not touch the fund. */
        ->and(Kwacha::toNgwee($this->ledger->balance($this->cycle)))->toBe(250_000);

    $entry = $this->claims->pay($claim->fresh(), $this->chair, $this->treasurer);

    expect($claim->fresh()->status)->toBe(GrantClaimStatus::Paid)
        ->and(Kwacha::toNgwee($entry->amount_ngwee))->toBe(-100_000)
        ->and($entry->type)->toBe(SocialFundTransactionType::FuneralGrant)
        ->and($entry->reference_id)->toBe($claim->id)
        ->and(Kwacha::toNgwee($this->ledger->balance($this->cycle)))->toBe(150_000);
});

it('refuses to approve a claim on behalf of the claimant', function () {
    $chairClaim = funeralClaim($this->cycle, $this->chair, FuneralRelationship::Spouse);

    $this->claims->approve($chairClaim, $this->chair, $this->treasurer);
})->throws(DomainRuleException::class, 'cannot stand as an approver on their own request');

it('refuses to pay a claim that has not been approved', function () {
    $claim = funeralClaim($this->cycle, $this->member, FuneralRelationship::Child);

    $this->claims->pay($claim, $this->chair, $this->treasurer);
})->throws(DomainRuleException::class, 'Only an approved claim can be paid');

it('refuses to pay a grant the fund cannot cover', function () {
    /* Drain the fund to K50 first. */
    $this->ledger->pay(
        $this->cycle,
        SocialFundTransactionType::GatheringExpense,
        Kwacha::of(2_450),
        Carbon::today(),
        $this->treasurer,
        $this->chair,
    );

    $claim = funeralClaim($this->cycle, $this->member, FuneralRelationship::Parent);
    $this->claims->approve($claim, $this->chair, $this->treasurer);

    $this->claims->pay($claim->fresh(), $this->chair, $this->treasurer);
})->throws(InsufficientSocialFundException::class);

it('pays a unity baby grant of five hundred kwacha through the same two steps', function () {
    $claim = UnityBabyClaim::factory()->for($this->cycle)->for($this->member)->create(['amount_ngwee' => 50_000]);

    $this->claims->approve($claim, $this->chair, $this->treasurer);
    $entry = $this->claims->pay($claim->fresh(), $this->chair, $this->treasurer);

    expect(Kwacha::toNgwee($entry->amount_ngwee))->toBe(-50_000)
        ->and($entry->type)->toBe(SocialFundTransactionType::UnityBabyGrant)
        ->and(Kwacha::toNgwee($this->ledger->balance($this->cycle)))->toBe(200_000);
});

it('records a rejection with its reason and closes the claim', function () {
    $claim = funeralClaim($this->cycle, $this->member, FuneralRelationship::Parent);

    $this->claims->reject($claim, $this->chair, 'Duplicate of claim #4.');

    expect($claim->fresh()->status)->toBe(GrantClaimStatus::Rejected)
        ->and($claim->fresh()->note)->toContain('Duplicate of claim #4.');

    expect(fn () => $this->claims->approve($claim->fresh(), $this->chair, $this->treasurer))
        ->toThrow(DomainRuleException::class);
});
