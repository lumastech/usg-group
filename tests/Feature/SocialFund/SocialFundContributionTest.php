<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Exceptions\InvalidSocialFundContributionException;
use App\Exceptions\MemberNotActiveException;
use App\Models\Cycle;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->member = memberWithRole($this->cycle);

    $this->contributions = app(SocialFundContributions::class);
});

it('accepts the contribution at exactly two hundred and fifty kwacha', function () {
    $entry = $this->contributions->record($this->member, Kwacha::of(250), $this->treasurer);

    expect(Kwacha::toNgwee($entry->amount_ngwee))->toBe(25_000)
        ->and(Kwacha::toNgwee(app(SocialFundLedger::class)->balance($this->cycle)))->toBe(25_000);
});

it('refuses a contribution that is not the exact amount', function (int $kwacha) {
    $this->contributions->record($this->member, Kwacha::of($kwacha), $this->treasurer);
})->with([100, 249, 251, 500])->throws(InvalidSocialFundContributionException::class);

it('refuses a second contribution from the same member', function () {
    $this->contributions->record($this->member, Kwacha::of(250), $this->treasurer);

    $this->contributions->record($this->member, Kwacha::of(250), $this->treasurer);
})->throws(InvalidSocialFundContributionException::class);

it('refuses a contribution from a member who has left the group', function () {
    $this->member->forceFill(['status' => MemberStatus::LeftEarly])->save();

    $this->contributions->record($this->member, Kwacha::of(250), $this->treasurer);
})->throws(MemberNotActiveException::class);

it('lists the active members who still owe the contribution', function () {
    $other = memberWithRole($this->cycle);

    $this->contributions->record($this->member, Kwacha::of(250), $this->treasurer);

    $outstanding = $this->contributions->outstanding($this->cycle)->pluck('id');

    expect($outstanding)->toContain($other->id, $this->treasurer->id)
        ->not->toContain($this->member->id);
});
