<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Loans\LoanApplicationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Domain\Savings\SavingsLedger;
use App\Domain\ShareOut\CycleCloser;
use App\Domain\ShareOut\ShareOutPreflight;
use App\Domain\SocialFund\SocialFundContributions;
use App\Enums\CycleStatus;
use App\Enums\MemberRole;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    /* Before December's trading day, so no month has traded yet. */
    Carbon::setTestNow('2025-12-01');

    $this->seed(RoleSeeder::class);

    $this->cycle = Cycle::factory()->create(['status' => CycleStatus::Active]);
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->january = $this->months->firstWhere('sequence', 2);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->member = memberWithRole($this->cycle);

    /* Everyone's dues paid, so the contributions check starts clear. */
    $this->cycle->members()->update(['joining_fee_paid' => true]);

    foreach ([$this->treasurer, $this->chair, $this->member] as $member) {
        app(SocialFundContributions::class)->record(
            $member->refresh(),
            $this->cycle->social_fund_contribution_ngwee,
            $this->treasurer,
            Carbon::parse('2025-12-01'),
        );
    }

    $this->preflight = app(ShareOutPreflight::class);
    $this->closer = app(CycleCloser::class);

    $this->verdicts = fn (): array => collect($this->preflight->items($this->cycle))
        ->mapWithKeys(fn ($item): array => [$item->key => $item->passed])
        ->all();
});

it('passes when nothing is outstanding', function () {
    expect($this->preflight->passes($this->cycle))->toBeTrue()
        ->and(($this->verdicts)())->toBe([
            'loans_closed' => true,
            'sessions_concluded' => true,
            'fund_reconciled' => true,
            'contributions_resolved' => true,
        ]);
});

it('blocks on a loan that is still running', function () {
    app(SavingsLedger::class)->record($this->member, $this->december, Kwacha::of(5_000), $this->treasurer);

    $loan = app(LoanApplicationService::class)->request(
        $this->member,
        Kwacha::of(10_000),
        $this->treasurer,
        Carbon::parse('2025-12-01 09:00'),
    );

    app(LoanApplicationService::class)->approve($loan, $this->chair, $this->treasurer);
    app(LoanDisbursementQueue::class)->disburse($loan->refresh(), $this->december, $this->treasurer);

    expect(($this->verdicts)()['loans_closed'])->toBeFalse()
        ->and($this->preflight->passes($this->cycle))->toBeFalse();

    $item = collect($this->preflight->items($this->cycle))->firstWhere('key', 'loans_closed');

    expect($item->outstandingCount)->toBe(1)
        ->and($item->outstanding[0]['label'])->toBe($this->member->full_name);
});

it('blocks on a month that traded but was never concluded', function () {
    /* December's trading day has passed and no session was ever opened for it. */
    Carbon::setTestNow('2026-02-01');

    expect(($this->verdicts)()['sessions_concluded'])->toBeFalse();

    $item = collect($this->preflight->items($this->cycle))->firstWhere('key', 'sessions_concluded');

    expect($item->outstanding[0]['label'])->toBe($this->december->label());
});

it('blocks on a member who has not paid their social fund contribution', function () {
    $latecomer = memberWithRole($this->cycle);
    $latecomer->forceFill(['joining_fee_paid' => true])->save();

    expect(($this->verdicts)()['contributions_resolved'])->toBeFalse();

    $item = collect($this->preflight->items($this->cycle))->firstWhere('key', 'contributions_resolved');

    expect($item->outstanding[0]['label'])->toBe($latecomer->full_name);
});

/*
 * The transition. Closing is the state the checklist is worked in; ShareOut is what a
 * clean checklist opens, and it is the only state a member may be paid in.
 */
it('walks the cycle from active to closing to share-out', function () {
    $this->closer->beginClosing($this->cycle, $this->chair);

    expect($this->cycle->refresh()->status)->toBe(CycleStatus::Closing);

    $this->closer->openShareOut($this->cycle, $this->chair);

    expect($this->cycle->refresh()->status)->toBe(CycleStatus::ShareOut);
});

it('refuses to open share-out straight from active', function () {
    expect(fn () => $this->closer->openShareOut($this->cycle, $this->chair))
        ->toThrow(DomainRuleException::class, 'pre-flight checklist');
});

it('refuses to open share-out while a check is outstanding', function () {
    memberWithRole($this->cycle, MemberRole::Member, ['joining_fee_paid' => true]);

    $this->closer->beginClosing($this->cycle, $this->chair);

    expect(fn () => $this->closer->openShareOut($this->cycle, $this->chair))
        ->toThrow(DomainRuleException::class, 'needs a written reason');

    expect($this->cycle->refresh()->status)->toBe(CycleStatus::Closing);
});

it('needs a second committee member to override a dirty checklist', function () {
    memberWithRole($this->cycle, MemberRole::Member, ['joining_fee_paid' => true]);

    $this->closer->beginClosing($this->cycle, $this->chair);

    expect(fn () => $this->closer->openShareOut($this->cycle, $this->chair, null, 'The member has since paid in cash.'))
        ->toThrow(DomainRuleException::class, 'second committee member');

    $cycle = $this->closer->openShareOut(
        $this->cycle,
        $this->chair,
        $this->treasurer,
        'The member has since paid in cash; the receipt is with the minutes.',
    );

    expect($cycle->status)->toBe(CycleStatus::ShareOut);
});

it('refuses an override signed by the same person twice', function () {
    memberWithRole($this->cycle, MemberRole::Member, ['joining_fee_paid' => true]);

    $this->closer->beginClosing($this->cycle, $this->chair);

    expect(fn () => $this->closer->openShareOut($this->cycle, $this->chair, $this->chair, 'Signed alone.'))
        ->toThrow(DomainRuleException::class, 'second, different committee member');
});
