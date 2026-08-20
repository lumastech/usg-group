<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Savings\SavingsLedger;
use App\Enums\SavingsTransactionType;
use App\Exceptions\ImmutableLedgerException;
use App\Exceptions\InvalidSavingsAmountException;
use App\Exceptions\LockdownSavingsCapException;
use App\Exceptions\MemberNotActiveException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\SavingsTransaction;
use App\Support\Kwacha;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->ledger = app(SavingsLedger::class);
    $this->cycle = Cycle::factory()->create();
    $this->months = app(CycleMonthPlanner::class)->plan($this->cycle);
    $this->december = $this->months->firstWhere('sequence', 1);
    $this->september = $this->months->firstWhere('sequence', 10);
    $this->member = Member::factory()->for($this->cycle)->create();
    $this->treasurer = Member::factory()->for($this->cycle)->create();
});

it('accepts savings in exact multiples of five hundred kwacha', function (int $kwacha) {
    $transaction = $this->ledger->record(
        $this->member,
        $this->december,
        Kwacha::of($kwacha),
        $this->treasurer,
    );

    expect($transaction->amount_ngwee->isEqualTo(Kwacha::of($kwacha)))->toBeTrue()
        ->and($transaction->type)->toBe(SavingsTransactionType::Contribution);
})->with([500, 1000, 1500, 3000, 30000, 100000]);

it('rejects savings below the five hundred kwacha minimum', function (int $kwacha) {
    $this->ledger->record($this->member, $this->december, Kwacha::of($kwacha), $this->treasurer);
})->with([0, 100, 250, 499])->throws(InvalidSavingsAmountException::class, 'at least K500.00');

it('rejects savings that are not a whole multiple of five hundred', function (int $kwacha) {
    $this->ledger->record($this->member, $this->december, Kwacha::of($kwacha), $this->treasurer);
})->with([501, 750, 1200, 2750])->throws(InvalidSavingsAmountException::class, 'increments of K500.00');

it('rejects a fractional kwacha amount', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of('500.50'), $this->treasurer);
})->throws(InvalidSavingsAmountException::class, 'increments of K500.00');

it('caps savings at five hundred kwacha from september to the end of the cycle', function (int $sequence) {
    $month = $this->months->firstWhere('sequence', $sequence);

    $this->ledger->record($this->member, $month, Kwacha::of(1000), $this->treasurer);
})->with([10, 11, 12])->throws(InvalidSavingsAmountException::class, 'capped at K500.00');

it('still allows the minimum five hundred during the lockdown months', function () {
    $transaction = $this->ledger->record(
        $this->member,
        $this->september,
        Kwacha::of(500),
        $this->treasurer,
    );

    expect($transaction->exists)->toBeTrue();
});

it('does not cap savings in august, the month before lockdown', function () {
    $august = $this->months->firstWhere('sequence', 9);

    $transaction = $this->ledger->record($this->member, $august, Kwacha::of(5000), $this->treasurer);

    expect($transaction->amount_ngwee->isEqualTo(Kwacha::of(5000)))->toBeTrue();
});

it('exempts adjustments and imported openings from the increment rules', function () {
    $transaction = $this->ledger->record(
        $this->member,
        $this->december,
        Kwacha::of('1234.56'),
        $this->treasurer,
        SavingsTransactionType::ImportOpening,
    );

    expect($transaction->exists)->toBeTrue();
});

it('accumulates savings across the months of the cycle', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(30000), $this->treasurer);
    $this->ledger->record($this->member, $this->months->firstWhere('sequence', 2), Kwacha::of(1000), $this->treasurer);
    $this->ledger->record($this->member, $this->months->firstWhere('sequence', 3), Kwacha::of(500), $this->treasurer);

    $cumulative = $this->ledger->cumulativeSavings($this->member, $this->months->firstWhere('sequence', 3));

    expect(Kwacha::format($cumulative))->toBe('K31,500.00');
});

it('excludes later months from the running total', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(30000), $this->treasurer);
    $this->ledger->record($this->member, $this->months->firstWhere('sequence', 5), Kwacha::of(2000), $this->treasurer);

    $cumulative = $this->ledger->cumulativeSavings($this->member, $this->months->firstWhere('sequence', 2));

    expect(Kwacha::format($cumulative))->toBe('K30,000.00');
});

it('writes an activity log entry naming the member who recorded it', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(500), $this->treasurer);

    $activity = Activity::query()->where('log_name', 'money')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('K500.00')
        ->and($activity->properties['actor_member_id'])->toBe($this->treasurer->id);
});

it('counts the whole month against the lockdown cap, not each deposit', function () {
    $this->ledger->record($this->member, $this->september, Kwacha::of(500), $this->treasurer);

    $this->ledger->record($this->member, $this->september, Kwacha::of(500), $this->treasurer);
})->throws(LockdownSavingsCapException::class, 'K500.00 is already recorded for September 2026');

it('lets a member save the full cap in one deposit during lockdown', function () {
    $transaction = $this->ledger->record($this->member, $this->september, Kwacha::of(500), $this->treasurer);

    expect(Kwacha::format($this->ledger->savedInMonth($this->member, $this->september)))->toBe('K500.00')
        ->and($transaction->exists)->toBeTrue();
});

it('caps each lockdown month separately', function () {
    $october = $this->months->firstWhere('sequence', 11);

    $this->ledger->record($this->member, $this->september, Kwacha::of(500), $this->treasurer);
    $this->ledger->record($this->member, $october, Kwacha::of(500), $this->treasurer);

    expect(Kwacha::format($this->ledger->savedInMonth($this->member, $october)))->toBe('K500.00');
});

it('does not count another members savings against this members cap', function () {
    $other = Member::factory()->for($this->cycle)->create();

    $this->ledger->record($other, $this->september, Kwacha::of(500), $this->treasurer);
    $transaction = $this->ledger->record($this->member, $this->september, Kwacha::of(500), $this->treasurer);

    expect($transaction->exists)->toBeTrue();
});

it('accepts several deposits in a month outside the lockdown', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(2000), $this->treasurer);
    $this->ledger->record($this->member, $this->december, Kwacha::of(1500), $this->treasurer);

    expect(Kwacha::format($this->ledger->savedInMonth($this->member, $this->december)))->toBe('K3,500.00');
});

it('refuses a contribution from a member who is no longer active', function (string $state) {
    $member = Member::factory()->for($this->cycle)->{$state}()->create();

    $this->ledger->record($member, $this->december, Kwacha::of(500), $this->treasurer);
})->with(['leftEarly', 'expelled', 'deceased'])->throws(MemberNotActiveException::class);

it('still allows an adjustment against a member who has left', function () {
    $member = Member::factory()->for($this->cycle)->leftEarly()->create();

    $transaction = $this->ledger->record(
        $member,
        $this->december,
        Kwacha::of(500)->negated(),
        $this->treasurer,
        SavingsTransactionType::Adjustment,
    );

    expect($transaction->exists)->toBeTrue();
});

it('refuses to edit a posted entry', function () {
    $transaction = $this->ledger->record($this->member, $this->december, Kwacha::of(500), $this->treasurer);

    $transaction->update(['amount_ngwee' => Kwacha::of(1000)]);
})->throws(ImmutableLedgerException::class, 'reversing adjustment');

it('refuses to delete a posted entry', function () {
    $transaction = $this->ledger->record($this->member, $this->december, Kwacha::of(500), $this->treasurer);

    $transaction->delete();
})->throws(ImmutableLedgerException::class, 'reversing adjustment');

it('corrects a mistake with a reversing adjustment, leaving both entries on the ledger', function () {
    $this->ledger->record($this->member, $this->december, Kwacha::of(1500), $this->treasurer);

    $this->ledger->record(
        $this->member,
        $this->december,
        Kwacha::of(1000)->negated(),
        $this->treasurer,
        SavingsTransactionType::Adjustment,
    );

    expect(Kwacha::format($this->ledger->cumulativeSavings($this->member, $this->december)))->toBe('K500.00')
        ->and(SavingsTransaction::where('member_id', $this->member->id)->count())->toBe(2);
});
