<?php

use App\Domain\Members\MembershipRegistrar;
use App\Enums\MemberStatus;
use App\Enums\NextOfKinRelationship;
use App\Exceptions\JoiningFeeBelowMinimumException;
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

it('refuses a joining fee below the tier minimum', function () {
    $this->registrar->register(
        $this->cycle,
        ['full_name' => 'Short Payer', 'joining_fee_ngwee' => 99_900],
        Carbon::parse('2025-12-05'),
    );
})->throws(JoiningFeeBelowMinimumException::class, 'at least K1,000.00');

it('refuses the standard fee from someone registering late', function () {
    $this->registrar->register(
        $this->cycle,
        ['full_name' => 'Late Joiner', 'joining_fee_ngwee' => 100_000],
        Carbon::parse('2026-02-14'),
    );
})->throws(JoiningFeeBelowMinimumException::class, 'at least K2,000.00');

it('records a fee paid above the minimum as given', function () {
    $member = $this->registrar->register(
        $this->cycle,
        ['full_name' => 'Generous Joiner', 'joining_fee_ngwee' => 150_000],
        Carbon::parse('2025-12-05'),
    );

    expect($member->getRawOriginal('joining_fee_ngwee'))->toBe(150_000);
});

it('stores the next of kin nominated at registration', function () {
    $member = $this->registrar->register($this->cycle, [
        'full_name' => 'Nominator',
        'next_of_kin' => [
            ['name' => 'Pamela Kashweka', 'phone' => '0977496538', 'relationship' => 'Sister'],
            ['name' => 'Blank Row Ignored'],
            ['name' => null],
        ],
    ], Carbon::parse('2025-12-05'));

    $kin = $member->nextOfKin()->orderBy('id')->get();

    expect($kin)->toHaveCount(2)
        ->and($kin->first()->name)->toBe('Pamela Kashweka')
        ->and($kin->first()->relationship)->toBe(NextOfKinRelationship::Sibling);
});

it('replaces the whole nominee list when syncing, since the form is a repeater', function () {
    $member = $this->registrar->register($this->cycle, [
        'full_name' => 'Changer',
        'next_of_kin' => [['name' => 'First Nominee', 'relationship' => 'Spouse']],
    ], Carbon::parse('2025-12-05'));

    $this->registrar->syncNextOfKin($member, [['name' => 'Second Nominee', 'relationship' => 'child']]);

    expect($member->nextOfKin()->pluck('name')->all())->toBe(['Second Nominee']);
});
