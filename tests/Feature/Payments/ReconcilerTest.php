<?php

use App\Domain\Payments\PaymentResult;
use App\Domain\Payments\Reconciler;
use App\Enums\PaymentStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\PaymentReconciliation;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->gateway = fakeGateway();
    $this->cycle = Cycle::factory()->create();
    $this->member = Member::factory()->for($this->cycle)->create();
    $this->reconciler = app(Reconciler::class);

    $this->from = Carbon::today()->subDay();
    $this->to = Carbon::today();

    $this->providerRow = fn (string $reference, int $ngwee = 50_000, PaymentStatus $status = PaymentStatus::Successful): PaymentResult => new PaymentResult(
        status: $status,
        providerId: 'txn-'.$reference,
        providerReference: $reference,
        amountNgwee: $ngwee,
        feeNgwee: 1_250,
    );

    $this->intent = fn (string $reference, PaymentStatus $status, int $ngwee = 50_000): PaymentIntent => PaymentIntent::factory()
        ->for($this->cycle)
        ->for($this->member)
        ->create(['reference' => $reference, 'status' => $status, 'amount_ngwee' => $ngwee]);
});

it('says so when both sides agree', function (): void {
    ($this->intent)('usg-sav-00001-1', PaymentStatus::Posted);
    $this->gateway->collectionFeed = [($this->providerRow)('usg-sav-00001-1')];

    $result = $this->reconciler->run($this->cycle, $this->from, $this->to);

    expect($result->agrees())->toBeTrue()
        ->and($result->collections_count)->toBe(1)
        ->and($result->collections_ngwee->getMinorAmount()->toInt())->toBe(50_000)
        ->and($result->fees_ngwee->getMinorAmount()->toInt())->toBe(1_250);
});

it('catches money the provider moved that this system never heard about', function (): void {
    $this->gateway->collectionFeed = [($this->providerRow)('usg-sav-09999-1')];

    $result = $this->reconciler->run($this->cycle, $this->from, $this->to);

    expect($result->unmatched_count)->toBe(1)
        ->and($result->unmatched[0]['side'])->toBe('provider')
        ->and($result->unmatched[0]['reason'])->toContain('no record of it');
});

it('catches money that arrived but never reached the ledgers', function (): void {
    ($this->intent)('usg-sav-00002-1', PaymentStatus::Successful);
    $this->gateway->collectionFeed = [($this->providerRow)('usg-sav-00002-1')];

    $result = $this->reconciler->run($this->cycle, $this->from, $this->to);

    expect($result->unmatched_count)->toBe(1)
        ->and($result->unmatched[0]['reason'])->toContain('the ledgers have not taken it');
});

it('catches an amount that does not agree', function (): void {
    ($this->intent)('usg-sav-00003-1', PaymentStatus::Posted, 50_000);
    $this->gateway->collectionFeed = [($this->providerRow)('usg-sav-00003-1', 25_000)];

    $result = $this->reconciler->run($this->cycle, $this->from, $this->to);

    expect($result->unmatched_count)->toBe(1)
        ->and($result->unmatched[0]['recorded_ngwee'])->toBe(50_000)
        ->and($result->unmatched[0]['amount_ngwee'])->toBe(25_000);
});

it('catches a payment this system believes in that the provider does not list', function (): void {
    ($this->intent)('usg-pay-00004-1', PaymentStatus::Posted);

    $result = $this->reconciler->run($this->cycle, $this->from, $this->to);

    expect($result->unmatched_count)->toBe(1)
        ->and($result->unmatched[0]['side'])->toBe('ours');
});

it('leaves a payment still in flight out of the comparison', function (): void {
    ($this->intent)('usg-sav-00005-1', PaymentStatus::AwaitingAuthorization);

    expect($this->reconciler->run($this->cycle, $this->from, $this->to)->agrees())->toBeTrue();
});

it('records the provider balance beside the day\'s figures', function (): void {
    $this->gateway->balanceNgwee = 9_755_900;

    expect($this->reconciler->run($this->cycle, $this->from, $this->to)->provider_balance_ngwee->getMinorAmount()->toInt())
        ->toBe(9_755_900);
});

it('keeps one row per day rather than one per run', function (): void {
    $this->reconciler->run($this->cycle, $this->from, $this->to);
    $this->reconciler->run($this->cycle, $this->from, $this->to);

    expect(PaymentReconciliation::count())->toBe(1);
});

it('still writes the day\'s row when the provider will not say what the balance is', function (): void {
    $this->gateway->balanceUnavailable = true;

    $result = $this->reconciler->run($this->cycle, $this->from, $this->to);

    expect($result->provider_balance_ngwee)->toBeNull();
});

it('runs from the command line', function (): void {
    $this->artisan('unity:reconcile-payments', ['--cycle' => $this->cycle->id])->assertSuccessful();

    expect(PaymentReconciliation::count())->toBe(1);
});
