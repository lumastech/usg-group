<?php

use App\Domain\Cycles\CycleMonthPlanner;
use App\Domain\Payments\PayoutDestinationService;
use App\Domain\Wallets\WalletLedger;
use App\Domain\Wallets\WalletRegistry;
use App\Domain\Wallets\WalletShareOutRunner;
use App\Enums\MemberRole;
use App\Enums\WalletTransferPurpose;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\Payout;
use App\Models\PayoutDestination;
use App\Models\WalletTransfer;
use App\Support\Kwacha;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->gateway = fakeGateway();

    $this->cycle = Cycle::factory()->create();
    app(CycleMonthPlanner::class)->plan($this->cycle);

    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->chair = memberWithRole($this->cycle, MemberRole::Chairperson);

    $this->registry = app(WalletRegistry::class);
    $this->ledger = app(WalletLedger::class);
    $this->runner = app(WalletShareOutRunner::class);

    $this->group = $this->registry->group($this->cycle);

    $this->room = collect(range(1, 3))->map(function (int $index): Member {
        $member = memberWithRole($this->cycle, MemberRole::Member, [
            'full_name' => "Member {$index}",
            'member_number' => $index,
        ]);

        Payout::factory()->for($this->cycle)->for($member)->create(['amount_ngwee' => 500_000]);

        return $member;
    });
});

it('refuses to start unless the group can cover the whole room', function () {
    $this->registry->recordOpeningFloat($this->cycle, Kwacha::of(10_000), $this->treasurer);

    /* K10,000 against K15,000 owed. Two thirds of a room paid and then stopped is the
       one outcome the committee cannot recover from in the room. */
    expect($this->runner->preview($this->cycle)['covered'])->toBeFalse();

    $this->runner->credit($this->cycle, $this->treasurer, $this->chair);
})->throws(DomainRuleException::class, 'Short by');

it('credits every payout into a member wallet, to the exact ngwee', function () {
    $this->registry->recordOpeningFloat($this->cycle, Kwacha::of(20_000), $this->treasurer);

    $result = $this->runner->credit($this->cycle, $this->treasurer, $this->chair);

    expect($result['paid_count'])->toBe(3)
        ->and($result['failed_count'])->toBe(0)
        ->and($result['paid_ngwee'])->toBe(1_500_000);

    foreach ($this->room as $member) {
        expect(Kwacha::toNgwee($this->registry->forMember($member, $this->cycle)->balance()))
            ->toBe(500_000);
    }

    expect(Kwacha::toNgwee($this->group->balance()))->toBe(2_000_000 - 1_500_000)
        ->and(WalletTransfer::query()->acrossCycles()
            ->where('purpose', WalletTransferPurpose::ShareOut->value)->count())->toBe(3)
        ->and(Payout::query()->acrossCycles()->whereNull('paid_at')->count())->toBe(0);
});

it('sends what is in the wallets out to where members said', function () {
    $this->registry->recordOpeningFloat($this->cycle, Kwacha::of(20_000), $this->treasurer);
    $this->runner->credit($this->cycle, $this->treasurer, $this->chair);

    /* Two of the three have said where their money goes. */
    foreach ($this->room->take(2) as $index => $member) {
        $this->gateway->resolvedName = $member->full_name;
        $destination = app(PayoutDestinationService::class)
            ->addMobileMoney($member, '097743357'.$index, null, $this->treasurer);
        PayoutDestination::query()->whereKey($destination->id)->update(['updated_at' => now()->subWeek()]);
    }

    $result = $this->runner->withdrawAll($this->cycle, $this->treasurer);

    expect($result['sent_count'])->toBe(2)
        ->and($result['by_hand'])->toHaveCount(1)
        ->and($result['by_hand'][0]['reason'])->toContain('by hand')
        ->and($this->gateway->transfers)->toHaveCount(2);
});

it('will not start the batch when the provider account is short', function () {
    $this->registry->recordOpeningFloat($this->cycle, Kwacha::of(20_000), $this->treasurer);
    $this->runner->credit($this->cycle, $this->treasurer, $this->chair);

    $this->gateway->resolvedName = $this->room[0]->full_name;
    $destination = app(PayoutDestinationService::class)
        ->addMobileMoney($this->room[0], '0977433571', null, $this->treasurer);
    PayoutDestination::query()->whereKey($destination->id)->update(['updated_at' => now()->subWeek()]);

    $this->gateway->balanceNgwee = 100;

    $this->runner->withdrawAll($this->cycle, $this->treasurer);
})->throws(DomainRuleException::class, 'not enough');

it('still needs two signatures to credit the room', function () {
    $this->registry->recordOpeningFloat($this->cycle, Kwacha::of(20_000), $this->treasurer);

    $result = $this->runner->credit($this->cycle, $this->treasurer, $this->treasurer);

    expect($result['paid_count'])->toBe(0)
        ->and($result['failed_count'])->toBe(3);
});
