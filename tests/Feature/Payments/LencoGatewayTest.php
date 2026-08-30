<?php

use App\Domain\Payments\CollectionRequest;
use App\Domain\Payments\Lenco\LencoGateway;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\TransferRequest;
use App\Enums\FeeBearer;
use App\Enums\MobileMoneyOperator;
use App\Enums\PaymentStatus;
use App\Enums\PayoutDestinationType;
use App\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('payments.default', 'lenco');
    config()->set('payments.gateways.lenco.base_url', 'https://api.lenco.test/access/v2');
    config()->set('payments.gateways.lenco.api_token', 'test-token');
    config()->set('payments.gateways.lenco.account_id', 'acct-uuid');
    config()->set('payments.gateways.lenco.public_key', 'pub_test');
});

function lencoBody(array $data, bool $status = true, array $extra = []): array
{
    return array_merge(['status' => $status, 'message' => '', 'data' => $data], $extra);
}

it('is what the container hands out once the group has an account', function (): void {
    expect(app(PaymentGateway::class))->toBeInstanceOf(LencoGateway::class);
});

it('asks a wallet for money in kwacha, not ngwee', function (): void {
    Http::fake(['*/collections/mobile-money' => Http::response(lencoBody([
        'id' => 'e809a3de',
        'reference' => 'usg-sav-00001-1',
        'lencoReference' => '240730008',
        'amount' => '500.00',
        'status' => 'pay-offline',
        'initiatedAt' => '2026-08-04T09:00:00.000Z',
        'mobileMoneyDetails' => ['accountName' => 'Chanda Mwansa', 'operator' => 'airtel'],
    ]))]);

    $result = app(PaymentGateway::class)->collect(new CollectionRequest(
        reference: 'usg-sav-00001-1',
        amountNgwee: 50_000,
        phone: '+260977433571',
    ));

    Http::assertSent(fn ($request): bool => $request['amount'] === '500.00'
        && $request['phone'] === '0977433571'
        && $request['operator'] === 'airtel'
        && $request['bearer'] === 'customer'
        && $request['reference'] === 'usg-sav-00001-1');

    expect($result->status)->toBe(PaymentStatus::AwaitingAuthorization)
        ->and($result->amountNgwee)->toBe(50_000)
        ->and($result->accountName)->toBe('Chanda Mwansa');
});

it('works the network out from the number when it is not told', function (): void {
    Http::fake(['*' => Http::response(lencoBody(['status' => 'pay-offline', 'reference' => 'r']))]);

    app(PaymentGateway::class)->collect(new CollectionRequest(
        reference: 'usg-sav-00002-1',
        amountNgwee: 50_000,
        phone: '0961111111',
    ));

    Http::assertSent(fn ($request): bool => $request['operator'] === 'mtn');
});

/**
 * The prompt reaches the handset and the provider takes its time answering. cURL gives
 * up first, and what comes back must be this module's own exception — not a raw
 * ConnectionException escaping to a 500 page while the member's phone is ringing.
 */
it('turns a request that timed out into a refusal it does not know the outcome of', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    try {
        app(PaymentGateway::class)->collect(new CollectionRequest(
            reference: 'usg-sav-00004-1',
            amountNgwee: 50_000,
            phone: '0977433571',
        ));

        $this->fail('The timeout should have been raised as a PaymentGatewayException.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->outcomeUnknown)->toBeTrue()
            ->and($exception->isRetryable())->toBeTrue()
            ->and($exception->reason())->toContain('did not answer in time');
    }
});

/** A refusal the provider actually sent is known: nothing moved. */
it('does not call a refusal the provider sent an unknown outcome', function (): void {
    Http::fake(['*' => Http::response(['status' => false, 'message' => 'Insufficient funds', 'errorCode' => '02'], 400)]);

    try {
        app(PaymentGateway::class)->collect(new CollectionRequest(
            reference: 'usg-sav-00005-1',
            amountNgwee: 50_000,
            phone: '0977433571',
        ));

        $this->fail('The refusal should have been raised.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->outcomeUnknown)->toBeFalse();
    }
});

it('refuses to ask a number it cannot place on a network', function (): void {
    Http::fake();

    app(PaymentGateway::class)->collect(new CollectionRequest(
        reference: 'usg-sav-00003-1',
        amountNgwee: 50_000,
        phone: '0211123456',
    ));
})->throws(PaymentGatewayException::class);

it('reads a settled collection as settled, and keeps the fee beside the amount', function (): void {
    Http::fake(['*/collections/status/*' => Http::response(lencoBody([
        'id' => 'd7bd9ccb',
        'reference' => 'usg-sav-00001-1',
        'amount' => '500.00',
        'fee' => '12.50',
        'bearer' => 'customer',
        'status' => 'successful',
        'completedAt' => '2026-08-04T09:14:10.412Z',
        'settlementStatus' => 'settled',
        'settlement' => ['settledAt' => '2026-08-04T09:20:00.000Z'],
    ]))]);

    $result = app(PaymentGateway::class)->collectionStatus('usg-sav-00001-1');

    expect($result->status)->toBe(PaymentStatus::Settled)
        ->and($result->amountNgwee)->toBe(50_000)
        ->and($result->feeNgwee)->toBe(1_250)
        ->and($result->feeBearer)->toBe(FeeBearer::Customer)
        ->and($result->completedAt?->toDateString())->toBe('2026-08-04')
        ->and($result->settledAt)->not->toBeNull();
});

it('keeps a successful but unsettled collection apart from a settled one', function (): void {
    Http::fake(['*' => Http::response(lencoBody([
        'reference' => 'usg-sav-00001-1',
        'status' => 'successful',
        'settlementStatus' => 'pending',
    ]))]);

    expect(app(PaymentGateway::class)->collectionStatus('usg-sav-00001-1')->status)
        ->toBe(PaymentStatus::Successful);
});

it('carries the provider reason for a failure through untouched', function (): void {
    Http::fake(['*' => Http::response(lencoBody([
        'reference' => 'usg-sav-00001-1',
        'status' => 'failed',
        'reasonForFailure' => 'Not enough funds',
    ]))]);

    $result = app(PaymentGateway::class)->collectionStatus('usg-sav-00001-1');

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->reasonForFailure)->toBe('Not enough funds');
});

it('sends money to a wallet from the group account', function (): void {
    Http::fake(['*/transfers/mobile-money' => Http::response(lencoBody([
        'id' => '9525b4c6',
        'reference' => 'usg-dis-00009-1',
        'amount' => '5000.00',
        'fee' => '8.50',
        'status' => 'successful',
        'completedAt' => '2026-08-04T10:00:00.000Z',
        'creditAccount' => ['accountName' => 'Beata Jean', 'type' => 'mobile-money'],
    ]))]);

    $result = app(PaymentGateway::class)->transfer(new TransferRequest(
        reference: 'usg-dis-00009-1',
        amountNgwee: 500_000,
        type: PayoutDestinationType::MobileMoney,
        narration: 'Loan disbursement',
        phone: '0977433571',
        operator: MobileMoneyOperator::Airtel,
    ));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.lenco.test/access/v2/transfers/mobile-money'
        && $request['accountId'] === 'acct-uuid'
        && $request['amount'] === '5000.00'
        && $request['narration'] === 'Loan disbursement');

    expect($result->status)->toBe(PaymentStatus::Successful)
        ->and($result->feeNgwee)->toBe(850)
        ->and($result->feeBearer)->toBe(FeeBearer::Merchant)
        ->and($result->accountName)->toBe('Beata Jean');
});

it('sends money to a bank account down the bank endpoint', function (): void {
    Http::fake(['*/transfers/bank-account' => Http::response(lencoBody([
        'reference' => 'usg-pay-00003-1',
        'amount' => '12000.00',
        'status' => 'pending',
    ]))]);

    $result = app(PaymentGateway::class)->transfer(new TransferRequest(
        reference: 'usg-pay-00003-1',
        amountNgwee: 1_200_000,
        type: PayoutDestinationType::BankAccount,
        accountNumber: '9130000000000',
        bankId: '002',
    ));

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/transfers/bank-account')
        && $request['accountNumber'] === '9130000000000'
        && $request['bankId'] === '002');

    expect($result->status)->toBe(PaymentStatus::Pending);
});

it('prefers a saved recipient over loose account details', function (): void {
    Http::fake(['*' => Http::response(lencoBody(['reference' => 'r', 'status' => 'pending']))]);

    app(PaymentGateway::class)->transfer(new TransferRequest(
        reference: 'usg-pay-00004-1',
        amountNgwee: 10_000,
        type: PayoutDestinationType::MobileMoney,
        phone: '0977433571',
        operator: MobileMoneyOperator::Airtel,
        providerRecipientId: 'recipient-uuid',
    ));

    Http::assertSent(fn ($request): bool => $request['transferRecipientId'] === 'recipient-uuid'
        && ! isset($request['phone'])
        && ! isset($request['operator']));
});

it('refuses a transfer with nowhere to send it', function (): void {
    Http::fake();

    app(PaymentGateway::class)->transfer(new TransferRequest(
        reference: 'usg-pay-00005-1',
        amountNgwee: 10_000,
        type: PayoutDestinationType::BankAccount,
    ));
})->throws(PaymentGatewayException::class);

it('asks whose account it is before the group agrees to pay anybody', function (): void {
    Http::fake(['*/resolve/mobile-money' => Http::response(lencoBody([
        'type' => 'mobile-money',
        'accountName' => 'Beata Jean',
        'phone' => '0750000000',
        'operator' => 'zamtel',
    ]))]);

    $resolved = app(PaymentGateway::class)->resolveMobileMoney('0750000000', MobileMoneyOperator::Zamtel);

    expect($resolved->accountName)->toBe('Beata Jean')
        ->and($resolved->operator)->toBe('zamtel');
});

it('resolves a bank account with its bank', function (): void {
    Http::fake(['*/resolve/bank-account' => Http::response(lencoBody([
        'accountName' => 'Beata Jean',
        'accountNumber' => '9130000000000',
        'bank' => ['id' => '002', 'name' => 'Absa Bank', 'country' => 'zm'],
    ]))]);

    $resolved = app(PaymentGateway::class)->resolveBankAccount('9130000000000', '002');

    expect($resolved->accountName)->toBe('Beata Jean')
        ->and($resolved->bankName)->toBe('Absa Bank');
});

it('turns a provider refusal into something a committee member can read', function (): void {
    Http::fake(['*' => Http::response([
        'status' => false,
        'message' => 'Insufficient funds',
        'errorCode' => '02',
    ], 400)]);

    try {
        app(PaymentGateway::class)->transfer(new TransferRequest(
            reference: 'usg-pay-00006-1',
            amountNgwee: 10_000,
            type: PayoutDestinationType::MobileMoney,
            phone: '0977433571',
            operator: MobileMoneyOperator::Airtel,
        ));
    } catch (PaymentGatewayException $exception) {
        expect($exception->errorCode)->toBe('02')
            ->and($exception->reason())->toBe("There is not enough money in the group's Lenco account.")
            ->and($exception->isRetryable())->toBeTrue();

        return;
    }

    $this->fail('The gateway swallowed a provider refusal.');
});

it('treats a duplicate reference as its own kind of problem', function (): void {
    Http::fake(['*' => Http::response(['status' => false, 'message' => 'Duplicate reference', 'errorCode' => '04'], 400)]);

    try {
        app(PaymentGateway::class)->collect(new CollectionRequest(
            reference: 'usg-sav-00001-1',
            amountNgwee: 50_000,
            phone: '0977433571',
        ));
    } catch (PaymentGatewayException $exception) {
        expect($exception->isDuplicateReference())->toBeTrue()
            ->and($exception->isRetryable())->toBeFalse();

        return;
    }

    $this->fail('A duplicate reference went unnoticed.');
});

it('does not trust an HTTP 200 that carries a false status', function (): void {
    Http::fake(['*' => Http::response(['status' => false, 'message' => 'Payment details was not found', 'data' => null], 200)]);

    app(PaymentGateway::class)->collectionStatus('usg-sav-09999-1');
})->throws(PaymentGatewayException::class, 'Payment details was not found');

it('reads the balance the group actually has to spend', function (): void {
    Http::fake(['*/accounts/acct-uuid/balance' => Http::response(lencoBody([
        'currency' => 'ZMW',
        'availableBalance' => '97559.00',
        'ledgerBalance' => '99000.00',
    ]))]);

    expect(app(PaymentGateway::class)->balanceNgwee())->toBe(9_755_900);
});

it('lists the banks a member could be paid into', function (): void {
    Http::fake(['*/banks*' => Http::response(lencoBody([
        ['id' => '002', 'name' => 'Absa Bank', 'country' => 'zm'],
        ['id' => '003', 'name' => 'Zanaco', 'country' => 'zm'],
    ]))]);

    $banks = app(PaymentGateway::class)->banks();

    expect($banks)->toHaveCount(2)
        ->and($banks[1]->name)->toBe('Zanaco');
});

it('walks every page of a listing when reconciling', function (): void {
    Http::fake(['*/collections*' => Http::sequence()
        ->push(lencoBody(
            [['reference' => 'usg-sav-00001-1', 'amount' => '500.00', 'status' => 'successful']],
            extra: ['meta' => ['pageCount' => 2, 'currentPage' => 1]],
        ))
        ->push(lencoBody(
            [['reference' => 'usg-sav-00002-1', 'amount' => '500.00', 'status' => 'successful']],
            extra: ['meta' => ['pageCount' => 2, 'currentPage' => 2]],
        )),
    ]);

    $collections = app(PaymentGateway::class)->collectionsBetween(
        Carbon::parse('2026-08-01'),
        Carbon::parse('2026-08-31'),
    );

    expect($collections)->toHaveCount(2)
        ->and($collections[1]->providerReference)->toBe('usg-sav-00002-1');
});

it('hands the browser only the public key', function (): void {
    $config = app(PaymentGateway::class)->widgetConfig();

    expect($config['key'])->toBe('pub_test')
        ->and($config['channels'])->toBe(['card', 'mobile-money'])
        ->and(json_encode($config))->not->toContain('test-token');
});

it('will not move money without a token, rather than failing quietly', function (): void {
    config()->set('payments.gateways.lenco.api_token', '');
    Http::fake();

    app(PaymentGateway::class)->collectionStatus('usg-sav-00001-1');
})->throws(PaymentGatewayException::class, 'No Lenco API token is configured');

it('does not retry a write, because a retried debit is a second debit', function (): void {
    Http::fake(['*' => Http::response([], 500)]);

    try {
        app(PaymentGateway::class)->collect(new CollectionRequest(
            reference: 'usg-sav-00007-1',
            amountNgwee: 50_000,
            phone: '0977433571',
        ));
    } catch (PaymentGatewayException) {
        Http::assertSentCount(1);

        return;
    }

    $this->fail('A failing collection did not raise.');
});
