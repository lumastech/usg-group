<?php

use App\Domain\Payments\PayoutDestinationService;
use App\Domain\Payments\ShareOutPaymentRunner;
use App\Enums\MemberRole;
use App\Enums\PaymentPurpose;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\Payout;
use App\Models\PayoutDestination;
use Database\Seeders\RoleSeeder;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->gateway = fakeGateway();

    $this->cycle = Cycle::factory()->create();
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);
    $this->runner = app(ShareOutPaymentRunner::class);
    $this->destinations = app(PayoutDestinationService::class);

    $this->payable = function (string $name, int $kwacha, bool $withDestination = true): Payout {
        $member = Member::factory()->for($this->cycle)->create(['full_name' => $name]);

        if ($withDestination) {
            $this->gateway->resolvedName = $name;
            $destination = $this->destinations->addMobileMoney($member, '097'.fake()->numerify('#######'), null, $this->treasurer);
            PayoutDestination::query()->whereKey($destination->id)->update(['updated_at' => now()->subWeek()]);
        }

        return Payout::factory()->for($this->cycle)->for($member)->create(['amount_ngwee' => $kwacha * 100]);
    };
});

it('shows what would be sent and what has to be paid by hand', function (): void {
    ($this->payable)('Chanda Mwansa', 12_000);
    ($this->payable)('Gilbert Phiri', 9_000, withDestination: false);

    $preview = $this->runner->preview($this->cycle);

    expect($preview['payable_count'])->toBe(1)
        ->and($preview['payable_ngwee'])->toBe(1_200_000)
        ->and($preview['by_hand_count'])->toBe(1)
        ->and($preview['by_hand_ngwee'])->toBe(900_000)
        ->and($preview['can_run'])->toBeTrue();
});

it('shows the group how far short the account is', function (): void {
    ($this->payable)('Chanda Mwansa', 12_000);
    $this->gateway->balanceNgwee = 500_000;

    $preview = $this->runner->preview($this->cycle);

    expect($preview['shortfall_ngwee'])->toBe(700_000)
        ->and($preview['can_run'])->toBeFalse();
});

it('sends one transfer per member, each with its own reference', function (): void {
    ($this->payable)('Chanda Mwansa', 12_000);
    ($this->payable)('Beata Jean', 8_000);

    $result = $this->runner->run($this->cycle, $this->treasurer, $this->chair);

    expect($result['sent_count'])->toBe(2)
        ->and($result['sent_ngwee'])->toBe(2_000_000)
        ->and($this->gateway->transfers)->toHaveCount(2)
        ->and(collect($result['sent'])->pluck('reference')->unique())->toHaveCount(2);
});

it('refuses to start rather than stranding the group half paid', function (): void {
    ($this->payable)('Chanda Mwansa', 12_000);
    ($this->payable)('Beata Jean', 8_000);
    $this->gateway->balanceNgwee = 1_500_000;

    expect(fn () => $this->runner->run($this->cycle, $this->treasurer, $this->chair))
        ->toThrow(DomainRuleException::class, 'not enough to send');

    expect($this->gateway->transfers)->toBeEmpty()
        ->and(PaymentIntent::count())->toBe(0);
});

it('steps over a member it cannot pay and carries on with the rest', function (): void {
    ($this->payable)('Chanda Mwansa', 12_000);
    $stranded = ($this->payable)('Beata Jean', 8_000, withDestination: false);

    $result = $this->runner->run($this->cycle, $this->treasurer, $this->chair);

    expect($result['sent_count'])->toBe(1)
        ->and($result['by_hand'])->toHaveCount(1)
        ->and($result['by_hand'][0]['payout_id'])->toBe($stranded->id)
        ->and($result['by_hand'][0]['reason'])->toContain('by hand');
});

it('leaves out a payout that has already been paid', function (): void {
    $paid = ($this->payable)('Chanda Mwansa', 12_000);
    $paid->forceFill(['paid_at' => now()])->save();
    ($this->payable)('Beata Jean', 8_000);

    expect($this->runner->preview($this->cycle)['payable_count'])->toBe(1)
        ->and($this->runner->run($this->cycle, $this->treasurer, $this->chair)['sent_count'])->toBe(1);
});

it('will not run when there is nothing left to send', function (): void {
    expect(fn () => $this->runner->run($this->cycle, $this->treasurer, $this->chair))
        ->toThrow(DomainRuleException::class, 'nothing left to send');
});

it('records each transfer against its payout', function (): void {
    $payout = ($this->payable)('Chanda Mwansa', 12_000);

    $this->runner->run($this->cycle, $this->treasurer, $this->chair);

    $intent = PaymentIntent::first();

    expect($intent->purpose)->toBe(PaymentPurpose::Payout)
        ->and($intent->payable_id)->toBe($payout->id)
        ->and($intent->second_approver_member_id)->toBe($this->chair->id);
});

it('flags a destination the committee still has to look at, without refusing it', function (): void {
    $member = Member::factory()->for($this->cycle)->create(['full_name' => 'Chanda Mwansa']);
    $this->gateway->resolvedName = 'Somebody Else';
    $this->destinations->addMobileMoney($member, '0977433571', null, $this->treasurer);
    Payout::factory()->for($this->cycle)->for($member)->create(['amount_ngwee' => 1_200_000]);

    $preview = $this->runner->preview($this->cycle);

    expect($preview['rows'][0]['needs_confirmation'])->toBeTrue()
        ->and($preview['rows'][0]['account_name'])->toBe('Somebody Else')
        ->and($preview['payable_count'])->toBe(1);
});
