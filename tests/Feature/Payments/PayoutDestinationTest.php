<?php

use App\Domain\Payments\AccountNameMatcher;
use App\Domain\Payments\PayoutDestinationService;
use App\Enums\MemberRole;
use App\Enums\MobileMoneyOperator;
use App\Enums\PayoutDestinationType;
use App\Exceptions\DomainRuleException;
use App\Exceptions\PaymentGatewayException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PayoutDestination;
use App\Notifications\PayoutDestinationChangedNotice;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    $this->gateway = fakeGateway();
    $this->gateway->resolvedName = 'Chanda Mwansa';
    $this->cycle = Cycle::factory()->create();
    $this->member = Member::factory()->for($this->cycle)->create(['full_name' => 'Chanda Mwansa']);
    $this->treasurer = memberWithRole($this->cycle, MemberRole::Treasurer);
    $this->destinations = app(PayoutDestinationService::class);
});

it('scores a name against the member being paid', function (string $account, int $atLeast): void {
    expect(AccountNameMatcher::score('Chanda Mwansa', $account))->toBeGreaterThanOrEqual($atLeast);
})->with([
    ['Chanda Mwansa', 100],
    ['CHANDA MWANSA', 100],
    ['Mwansa Chanda', 100],
    ['Mr Chanda Mwansa', 100],
    ['C Mwansa', 100],
    ['Chanda Mary Mwansa', 60],
]);

it('marks a name that is somebody else entirely', function (): void {
    expect(AccountNameMatcher::score('Chanda Mwansa', 'Gilbert Phiri'))->toBeLessThan(50)
        ->and(AccountNameMatcher::isConfident(AccountNameMatcher::score('Chanda Mwansa', 'Gilbert Phiri')))
        ->toBeFalse();
});

it('asks the provider whose account it is before saving it', function (): void {
    $this->gateway->resolvedName = 'Chanda Mwansa';

    $destination = $this->destinations->addMobileMoney(
        $this->member,
        '+260977433571',
        MobileMoneyOperator::Airtel,
        $this->member,
    );

    expect($destination->type)->toBe(PayoutDestinationType::MobileMoney)
        ->and($destination->phone)->toBe('0977433571')
        ->and($destination->resolved_account_name)->toBe('Chanda Mwansa')
        ->and($destination->name_match_score)->toBe(100)
        ->and($destination->verified_at)->not->toBeNull()
        ->and($destination->is_default)->toBeTrue();
});

it('saves nothing when the provider cannot place the account', function (): void {
    $this->gateway->throw = new PaymentGatewayException('Account details was not found');

    expect(fn () => $this->destinations->addMobileMoney($this->member, '0977433571', MobileMoneyOperator::Airtel, $this->member))
        ->toThrow(PaymentGatewayException::class);

    expect(PayoutDestination::count())->toBe(0);
});

it('flags a destination in somebody else\'s name without refusing it', function (): void {
    $this->gateway->resolvedName = 'Gilbert Phiri';

    $destination = $this->destinations->addMobileMoney(
        $this->member,
        '0977433571',
        MobileMoneyOperator::Airtel,
        $this->member,
    );

    expect($destination->hasUnconfirmedNameMismatch())->toBeTrue()
        ->and($this->destinations->needsSecondSignature($destination))->toBeTrue();
});

it('will not let a member wave through the mismatch on their own account', function (): void {
    $this->gateway->resolvedName = 'Gilbert Phiri';
    $destination = $this->destinations->addMobileMoney($this->member, '0977433571', null, $this->member);

    expect(fn () => $this->destinations->confirmName($destination, $this->member))
        ->toThrow(DomainRuleException::class, 'not by the member being paid');
});

it('lets the committee confirm a mismatch on the record', function (): void {
    $this->gateway->resolvedName = 'Gilbert Phiri';
    $destination = $this->destinations->addMobileMoney($this->member, '0977433571', null, $this->member);

    $confirmed = $this->destinations->confirmName($destination, $this->treasurer);

    expect($confirmed->hasUnconfirmedNameMismatch())->toBeFalse()
        ->and($confirmed->name_match_confirmed_by_member_id)->toBe($this->treasurer->id);
});

it('lets a member keep both a bank account and a wallet, with one default', function (): void {
    $wallet = $this->destinations->addMobileMoney($this->member, '0977433571', null, $this->member);
    $bank = $this->destinations->addBankAccount($this->member, '002', '9130000000000', $this->member);

    expect($bank->refresh()->is_default)->toBeTrue()
        ->and($wallet->refresh()->is_default)->toBeFalse()
        ->and($this->member->payoutDestinations()->count())->toBe(2);

    $this->destinations->makeDefault($wallet, $this->member);

    expect($wallet->refresh()->is_default)->toBeTrue()
        ->and($bank->refresh()->is_default)->toBeFalse();
});

it('does not let the same account be added twice', function (): void {
    $first = $this->destinations->addMobileMoney($this->member, '0977433571', MobileMoneyOperator::Airtel, $this->member);
    $again = $this->destinations->addMobileMoney($this->member, '+260977433571', MobileMoneyOperator::Airtel, $this->member);

    expect($again->id)->toBe($first->id)
        ->and(PayoutDestination::count())->toBe(1);
});

it('promotes another destination when the default is removed', function (): void {
    $wallet = $this->destinations->addMobileMoney($this->member, '0977433571', null, $this->member);
    $bank = $this->destinations->addBankAccount($this->member, '002', '9130000000000', $this->member);

    $this->destinations->disable($bank, $this->treasurer);

    expect($bank->refresh()->disabled_at)->not->toBeNull()
        ->and($wallet->refresh()->is_default)->toBeTrue();
});

it('will not send money to a removed destination', function (): void {
    $wallet = $this->destinations->addMobileMoney($this->member, '0977433571', null, $this->member);
    $this->destinations->disable($wallet, $this->treasurer);

    expect(fn () => $this->destinations->assertPayable($wallet->refresh()))
        ->toThrow(DomainRuleException::class, 'has been removed');
});

it('will not send money to a destination nobody checked', function (): void {
    $destination = PayoutDestination::factory()->for($this->member)->unverified()->create();

    expect(fn () => $this->destinations->assertPayable($destination))
        ->toThrow(DomainRuleException::class, 'never been checked');
});

it('needs a second signature while a destination is still new', function (): void {
    $wallet = $this->destinations->addMobileMoney($this->member, '0977433571', null, $this->member);

    expect($this->destinations->needsSecondSignature($wallet))->toBeTrue();

    PayoutDestination::query()->whereKey($wallet->id)->update(['updated_at' => now()->subDays(5)]);

    expect($this->destinations->needsSecondSignature($wallet->refresh()))->toBeFalse();
});

it('tells the member their money is going somewhere new', function (): void {
    Notification::fake();

    $this->destinations->addMobileMoney($this->member, '0977433571', null, $this->treasurer);

    Notification::assertSentTo($this->member, PayoutDestinationChangedNotice::class);
});
