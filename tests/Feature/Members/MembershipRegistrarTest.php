<?php

use App\Domain\Members\MembershipRegistrar;
use App\Enums\MemberStatus;
use App\Exceptions\RegistrationClosedException;
use App\Models\Cycle;
use App\Support\Kwacha;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->registrar = app(MembershipRegistrar::class);
    $this->cycle = Cycle::factory()->create();
});

it('works out which month of the cycle a date falls in', function (string $date, int $sequence) {
    expect($this->registrar->monthSequenceFor($this->cycle, Carbon::parse($date)))->toBe($sequence);
})->with([
    ['2025-12-01', 1],
    ['2025-12-31', 1],
    ['2026-01-15', 2],
    ['2026-02-28', 3],
    ['2026-03-01', 4],
]);

it('charges the standard joining fee in the first two months', function () {
    $member = $this->registrar->register(
        $this->cycle,
        ['full_name' => 'Chanda Mwale'],
        Carbon::parse('2026-01-10'),
    );

    expect($member->joining_fee_ngwee->isEqualTo(Kwacha::of(1000)))->toBeTrue()
        ->and($member->joining_month_sequence)->toBe(2)
        ->and($member->status)->toBe(MemberStatus::Active);
});

it('charges the late registration fee to anyone joining in the third month', function () {
    $member = $this->registrar->register(
        $this->cycle,
        ['full_name' => 'Bwalya Tembo'],
        Carbon::parse('2026-02-14'),
    );

    expect($member->joining_fee_ngwee->isEqualTo(Kwacha::of(2000)))->toBeTrue()
        ->and($member->joining_month_sequence)->toBe(3);
});

it('locks registration after the third month of the cycle', function () {
    $this->registrar->register(
        $this->cycle,
        ['full_name' => 'Too Late'],
        Carbon::parse('2026-03-01'),
    );
})->throws(RegistrationClosedException::class, 'closed after month 3');

it('keeps registration open right up to the end of the third month', function () {
    $member = $this->registrar->register(
        $this->cycle,
        ['full_name' => 'Just In Time'],
        Carbon::parse('2026-02-28'),
    );

    expect($member->exists)->toBeTrue();
});

it('numbers members sequentially within the cycle', function () {
    $first = $this->registrar->register($this->cycle, ['full_name' => 'First Member'], Carbon::parse('2025-12-05'));
    $second = $this->registrar->register($this->cycle, ['full_name' => 'Second Member'], Carbon::parse('2025-12-06'));

    expect($second->member_number)->toBe($first->member_number + 1);
});

it('stores money as a ngwee integer and reads it back as kwacha', function () {
    $member = $this->registrar->register($this->cycle, ['full_name' => 'Ledger Check'], Carbon::parse('2025-12-05'));

    expect($member->getRawOriginal('joining_fee_ngwee'))->toBe(100_000)
        ->and(Kwacha::format($member->joining_fee_ngwee))->toBe('K1,000.00');
});
