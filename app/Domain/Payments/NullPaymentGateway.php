<?php

namespace App\Domain\Payments;

use App\Enums\MobileMoneyOperator;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentGatewayException;
use Carbon\CarbonInterface;
use Illuminate\Log\LogManager;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * The stand-in gateway: writes what it would have moved to the log and moves nothing.
 *
 * This is the default binding and stays so until the group holds a Lenco account. It
 * exists so the whole payment path — intents, the state machine, the poller, the
 * posting rules, every screen — is exercised end to end today rather than written blind
 * against an interface nothing calls. Collections come back needing authorisation and
 * never advance, which is deliberately the state a real one sits in while a member
 * looks at their handset.
 */
class NullPaymentGateway implements PaymentGateway
{
    public function __construct(protected LogManager $log) {}

    public function collect(CollectionRequest $request): PaymentResult
    {
        $this->note('collection requested', [
            'reference' => $request->reference,
            'amount_ngwee' => $request->amountNgwee,
            'phone' => $request->phone,
            'operator' => $request->operator?->value,
        ]);

        return new PaymentResult(
            status: PaymentStatus::AwaitingAuthorization,
            providerId: 'null-'.$request->reference,
            providerReference: $request->reference,
            amountNgwee: $request->amountNgwee,
            feeBearer: $request->bearer,
            initiatedAt: Carbon::now(),
        );
    }

    public function collectionStatus(string $reference): PaymentResult
    {
        return new PaymentResult(
            status: PaymentStatus::AwaitingAuthorization,
            providerId: 'null-'.$reference,
            providerReference: $reference,
        );
    }

    public function transfer(TransferRequest $request): PaymentResult
    {
        $this->note('transfer requested', [
            'reference' => $request->reference,
            'amount_ngwee' => $request->amountNgwee,
            'type' => $request->type->value,
        ]);

        return new PaymentResult(
            status: PaymentStatus::Pending,
            providerId: 'null-'.$request->reference,
            providerReference: $request->reference,
            amountNgwee: $request->amountNgwee,
            initiatedAt: Carbon::now(),
        );
    }

    public function transferStatus(string $reference): PaymentResult
    {
        return new PaymentResult(
            status: PaymentStatus::Pending,
            providerId: 'null-'.$reference,
            providerReference: $reference,
        );
    }

    public function resolveBankAccount(string $accountNumber, string $bankId): ResolvedAccount
    {
        throw $this->unconfigured('resolve a bank account');
    }

    public function resolveMobileMoney(string $phone, MobileMoneyOperator $operator): ResolvedAccount
    {
        throw $this->unconfigured('resolve a mobile money account');
    }

    /** @return array<int, BankOption> */
    public function banks(): array
    {
        return [];
    }

    public function balanceNgwee(): int
    {
        return 0;
    }

    /** @return array<int, PaymentResult> */
    public function collectionsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return [];
    }

    /** @return array<int, PaymentResult> */
    public function transfersBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return [];
    }

    /** @return array{key: string, script: string, channels: array<int, string>}|null */
    public function widgetConfig(): ?array
    {
        return null;
    }

    /**
     * Refusing rather than pretending.
     *
     * A resolve that quietly returned the member's own name would let a destination be
     * marked verified without anybody having checked anything, and the verified flag is
     * load-bearing for every transfer.
     */
    protected function unconfigured(string $action): PaymentGatewayException
    {
        return new PaymentGatewayException(
            "No payment gateway is configured, so the group cannot {$action} yet."
        );
    }

    /** @param array<string, mixed> $context */
    protected function note(string $message, array $context): void
    {
        $this->logger()->info("Payment ({$message} — no gateway configured, nothing moved)", $context);
    }

    protected function logger(): LoggerInterface
    {
        $channel = config('payments.gateways.null.log_channel');

        return $channel === null ? $this->log->driver() : $this->log->channel((string) $channel);
    }
}
