<?php

namespace Tests\Support;

use App\Domain\Payments\BankOption;
use App\Domain\Payments\CollectionRequest;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentResult;
use App\Domain\Payments\ResolvedAccount;
use App\Domain\Payments\TransferRequest;
use App\Enums\FeeBearer;
use App\Enums\MobileMoneyOperator;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A gateway that moves nothing and does exactly what the test tells it to.
 *
 * Bound in place of the real one so a test about what a settled payment does to the
 * ledgers is not also a test about HTTP.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<int, CollectionRequest> */
    public array $collections = [];

    /** @var array<int, TransferRequest> */
    public array $transfers = [];

    public PaymentStatus $collectStatus = PaymentStatus::AwaitingAuthorization;

    public PaymentStatus $transferStatus = PaymentStatus::Pending;

    public ?PaymentStatus $statusAnswer = null;

    public ?PaymentGatewayException $throw = null;

    public ?string $reasonForFailure = null;

    public ?int $feeNgwee = 850;

    public int $balanceNgwee = 100_000_000;

    /** The provider being unreachable for the balance alone, which is its own case. */
    public bool $balanceUnavailable = false;

    public string $resolvedName = 'Test Member';

    /** @var array<int, PaymentResult> */
    public array $collectionFeed = [];

    /** @var array<int, PaymentResult> */
    public array $transferFeed = [];

    public function collect(CollectionRequest $request): PaymentResult
    {
        $this->collections[] = $request;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->result($this->collectStatus, $request->reference, $request->amountNgwee, FeeBearer::Customer);
    }

    public function collectionStatus(string $reference): PaymentResult
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->result($this->statusAnswer ?? $this->collectStatus, $reference, null, FeeBearer::Customer);
    }

    public function transfer(TransferRequest $request): PaymentResult
    {
        $this->transfers[] = $request;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->result($this->transferStatus, $request->reference, $request->amountNgwee, FeeBearer::Merchant);
    }

    public function transferStatus(string $reference): PaymentResult
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return $this->result($this->statusAnswer ?? $this->transferStatus, $reference, null, FeeBearer::Merchant);
    }

    public function resolveBankAccount(string $accountNumber, string $bankId): ResolvedAccount
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new ResolvedAccount(
            accountName: $this->resolvedName,
            accountNumber: $accountNumber,
            bankId: $bankId,
            bankName: 'Absa Bank Zambia',
        );
    }

    public function resolveMobileMoney(string $phone, MobileMoneyOperator $operator): ResolvedAccount
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return new ResolvedAccount(
            accountName: $this->resolvedName,
            phone: $phone,
            operator: $operator->value,
        );
    }

    /** @return array<int, BankOption> */
    public function banks(): array
    {
        return [new BankOption('002', 'Absa Bank Zambia', 'zm')];
    }

    public function balanceNgwee(): int
    {
        if ($this->balanceUnavailable) {
            throw new PaymentGatewayException('Could not reach the payment provider.');
        }

        return $this->balanceNgwee;
    }

    /** @return array<int, PaymentResult> */
    public function collectionsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->collectionFeed;
    }

    /** @return array<int, PaymentResult> */
    public function transfersBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->transferFeed;
    }

    /** @return array{key: string, script: string, channels: array<int, string>}|null */
    public function widgetConfig(): ?array
    {
        return ['key' => 'pub_fake', 'script' => 'https://pay.test/inline.js', 'channels' => ['card', 'mobile-money']];
    }

    /** Makes the next call answer with this status. */
    public function willAnswer(PaymentStatus $status): static
    {
        $this->statusAnswer = $status;
        $this->collectStatus = $status;
        $this->transferStatus = $status;

        return $this;
    }

    public function willFail(string $reason = 'Not enough funds'): static
    {
        $this->reasonForFailure = $reason;

        return $this->willAnswer(PaymentStatus::Failed);
    }

    protected function result(PaymentStatus $status, string $reference, ?int $amountNgwee, FeeBearer $bearer): PaymentResult
    {
        return new PaymentResult(
            status: $status,
            providerId: 'fake-'.$reference,
            providerReference: $reference,
            amountNgwee: $amountNgwee,
            feeNgwee: $status->hasSucceeded() ? $this->feeNgwee : null,
            feeBearer: $bearer,
            initiatedAt: Carbon::now(),
            completedAt: $status->hasSucceeded() ? Carbon::now() : null,
            settledAt: $status === PaymentStatus::Settled ? Carbon::now() : null,
            reasonForFailure: $status === PaymentStatus::Failed ? $this->reasonForFailure : null,
            accountName: $this->resolvedName,
            raw: ['fake' => true, 'reference' => $reference],
        );
    }
}
