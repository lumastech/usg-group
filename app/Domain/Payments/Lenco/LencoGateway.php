<?php

namespace App\Domain\Payments\Lenco;

use App\Domain\Payments\BankOption;
use App\Domain\Payments\CollectionRequest;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentResult;
use App\Domain\Payments\ResolvedAccount;
use App\Domain\Payments\TransferRequest;
use App\Enums\FeeBearer;
use App\Enums\MobileMoneyOperator;
use App\Enums\PaymentStatus;
use App\Enums\PayoutDestinationType;
use App\Exceptions\PaymentGatewayException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Lenco, in this application's own terms.
 *
 * The whole provider vocabulary stops here: decimal amounts, camelCase keys and status
 * strings like "pay-offline" are translated on the way in and out, so nothing above
 * this class knows which gateway the group signed up with.
 */
class LencoGateway implements PaymentGateway
{
    public function __construct(protected LencoClient $client) {}

    public function collect(CollectionRequest $request): PaymentResult
    {
        $phone = $request->phone === null ? null : LencoOperator::normalisePhone($request->phone);
        $operator = $request->operator ?? ($request->phone === null ? null : LencoOperator::forPhone($request->phone));

        if ($phone === null || $operator === null) {
            throw new PaymentGatewayException(
                'A mobile money collection needs a Zambian number and the network it is on.'
            );
        }

        return $this->collectionResult($this->client->post('/collections/mobile-money', [
            'amount' => LencoAmount::toDecimal($request->amountNgwee),
            'reference' => $request->reference,
            'phone' => $phone,
            'operator' => $operator->value,
            'country' => $this->client->country(),
            'bearer' => $request->bearer->value,
        ]));
    }

    public function collectionStatus(string $reference): PaymentResult
    {
        return $this->collectionResult($this->client->get('/collections/status/'.rawurlencode($reference)));
    }

    public function transfer(TransferRequest $request): PaymentResult
    {
        return match ($request->type) {
            PayoutDestinationType::MobileMoney => $this->transferToMobileMoney($request),
            PayoutDestinationType::BankAccount => $this->transferToBankAccount($request),
        };
    }

    public function transferStatus(string $reference): PaymentResult
    {
        return $this->transferResult($this->client->get('/transfers/status/'.rawurlencode($reference)));
    }

    public function resolveBankAccount(string $accountNumber, string $bankId): ResolvedAccount
    {
        $data = $this->client->post('/resolve/bank-account', [
            'accountNumber' => $accountNumber,
            'bankId' => $bankId,
            'country' => $this->client->country(),
        ]);

        return new ResolvedAccount(
            accountName: (string) ($data['accountName'] ?? ''),
            accountNumber: $this->string($data, 'accountNumber'),
            bankId: $this->string($data['bank'] ?? [], 'id'),
            bankName: $this->string($data['bank'] ?? [], 'name'),
        );
    }

    public function resolveMobileMoney(string $phone, MobileMoneyOperator $operator): ResolvedAccount
    {
        $normalised = LencoOperator::normalisePhone($phone);

        if ($normalised === null) {
            throw new PaymentGatewayException("\"{$phone}\" is not a Zambian mobile number.");
        }

        $data = $this->client->post('/resolve/mobile-money', [
            'phone' => $normalised,
            'operator' => $operator->value,
            'country' => $this->client->country(),
        ]);

        return new ResolvedAccount(
            accountName: (string) ($data['accountName'] ?? ''),
            phone: $this->string($data, 'phone') ?? $normalised,
            operator: $this->string($data, 'operator') ?? $operator->value,
        );
    }

    /** @return array<int, BankOption> */
    public function banks(): array
    {
        $page = $this->client->getPage('/banks', ['country' => $this->client->country()]);

        return array_map(
            fn (array $bank): BankOption => new BankOption(
                id: (string) ($bank['id'] ?? ''),
                name: (string) ($bank['name'] ?? ''),
                country: $this->string($bank, 'country'),
            ),
            $page['data'],
        );
    }

    public function balanceNgwee(): int
    {
        $data = $this->client->get('/accounts/'.$this->client->accountId().'/balance');

        return LencoAmount::toNgwee((string) ($data['availableBalance'] ?? '0.00'));
    }

    /** @return array<int, PaymentResult> */
    public function collectionsBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->paged('/collections', $from, $to, fn (array $row): PaymentResult => $this->collectionResult($row));
    }

    /** @return array<int, PaymentResult> */
    public function transfersBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->paged('/transfers', $from, $to, fn (array $row): PaymentResult => $this->transferResult($row), [
            'accountId' => $this->client->accountId(),
        ]);
    }

    /** @return array{key: string, script: string, channels: array<int, string>}|null */
    public function widgetConfig(): ?array
    {
        $key = $this->client->config('public_key');

        if ($key === '') {
            return null;
        }

        return [
            'key' => $key,
            'script' => $this->client->config('widget_url', 'https://pay.lenco.co/js/v1/inline.js'),
            'channels' => ['card', 'mobile-money'],
        ];
    }

    /**
     * A saved recipient and a loose number are two different payloads, so they are two
     * different calls rather than one call full of conditionals.
     */
    protected function transferToMobileMoney(TransferRequest $request): PaymentResult
    {
        if ($request->providerRecipientId !== null) {
            return $this->transferResult($this->client->post('/transfers/mobile-money', [
                ...$this->transferBase($request),
                'transferRecipientId' => $request->providerRecipientId,
            ]));
        }

        $phone = $request->phone === null ? null : LencoOperator::normalisePhone($request->phone);

        if ($phone === null || $request->operator === null) {
            throw new PaymentGatewayException(
                'A mobile money transfer needs either a saved recipient or a number and its network.'
            );
        }

        return $this->transferResult($this->client->post('/transfers/mobile-money', [
            ...$this->transferBase($request),
            'phone' => $phone,
            'operator' => $request->operator->value,
            'country' => $this->client->country(),
        ]));
    }

    protected function transferToBankAccount(TransferRequest $request): PaymentResult
    {
        if ($request->providerRecipientId !== null) {
            return $this->transferResult($this->client->post('/transfers/bank-account', [
                ...$this->transferBase($request),
                'transferRecipientId' => $request->providerRecipientId,
            ]));
        }

        if ($request->accountNumber === null || $request->bankId === null) {
            throw new PaymentGatewayException(
                'A bank transfer needs either a saved recipient or an account number and its bank.'
            );
        }

        return $this->transferResult($this->client->post('/transfers/bank-account', [
            ...$this->transferBase($request),
            'accountNumber' => $request->accountNumber,
            'bankId' => $request->bankId,
            'country' => $this->client->country(),
        ]));
    }

    /**
     * What every transfer carries whichever way the recipient was given.
     *
     * @return array<string, string|int|null>
     */
    protected function transferBase(TransferRequest $request): array
    {
        return [
            'accountId' => $this->client->accountId(),
            'amount' => LencoAmount::toDecimal($request->amountNgwee),
            'reference' => $request->reference,
            'narration' => $request->narration,
        ];
    }

    /**
     * Walks a listing to the end.
     *
     * A month of a thirty-member group is a handful of pages; the cap is there so a
     * misread `pageCount` cannot spin the reconciliation command forever.
     *
     * @param  callable(array<string, mixed>): PaymentResult  $map
     * @param  array<string, mixed>  $extra
     * @return array<int, PaymentResult>
     */
    protected function paged(string $path, CarbonInterface $from, CarbonInterface $to, callable $map, array $extra = []): array
    {
        $results = [];
        $page = 1;

        do {
            $response = $this->client->getPage($path, $extra + [
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
                'page' => $page,
            ]);

            foreach ($response['data'] as $row) {
                $results[] = $map($row);
            }

            $pageCount = (int) ($response['meta']['pageCount'] ?? 1);
            $page++;
        } while ($page <= $pageCount && $page <= 50);

        return $results;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function collectionResult(array $data): PaymentResult
    {
        $settled = ($data['settlementStatus'] ?? null) === 'settled';
        $status = $this->collectionStatusFrom((string) ($data['status'] ?? ''), $settled);

        return new PaymentResult(
            status: $status,
            providerId: $this->string($data, 'id'),
            providerReference: $this->string($data, 'reference'),
            amountNgwee: LencoAmount::toNgweeOrNull($this->scalar($data, 'amount')),
            feeNgwee: LencoAmount::toNgweeOrNull($this->scalar($data, 'fee')),
            feeBearer: $this->bearerFrom($this->string($data, 'bearer')),
            initiatedAt: $this->time($data, 'initiatedAt'),
            completedAt: $this->time($data, 'completedAt'),
            settledAt: $settled ? $this->time($data['settlement'] ?? [], 'settledAt') : null,
            reasonForFailure: $this->string($data, 'reasonForFailure'),
            authorizationUrl: $this->string($data['meta']['authorization'] ?? [], 'redirect'),
            accountName: $this->string($data['mobileMoneyDetails'] ?? $data['cardDetails'] ?? [], 'accountName'),
            raw: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function transferResult(array $data): PaymentResult
    {
        return new PaymentResult(
            status: $this->transferStatusFrom((string) ($data['status'] ?? '')),
            providerId: $this->string($data, 'id'),
            providerReference: $this->string($data, 'reference'),
            amountNgwee: LencoAmount::toNgweeOrNull($this->scalar($data, 'amount')),
            feeNgwee: LencoAmount::toNgweeOrNull($this->scalar($data, 'fee')),
            feeBearer: FeeBearer::Merchant,
            initiatedAt: $this->time($data, 'initiatedAt'),
            completedAt: $this->time($data, 'completedAt'),
            reasonForFailure: $this->string($data, 'reasonForFailure'),
            accountName: $this->string($data['creditAccount'] ?? [], 'accountName'),
            raw: $data,
        );
    }

    /**
     * "pay-offline" and "3ds-auth-required" both mean the same thing to us: the money
     * is waiting on a person, not on the network.
     */
    protected function collectionStatusFrom(string $status, bool $settled): PaymentStatus
    {
        return match ($status) {
            'successful' => $settled ? PaymentStatus::Settled : PaymentStatus::Successful,
            'failed' => PaymentStatus::Failed,
            'pay-offline', '3ds-auth-required', 'otp-required' => PaymentStatus::AwaitingAuthorization,
            default => PaymentStatus::Pending,
        };
    }

    protected function transferStatusFrom(string $status): PaymentStatus
    {
        return match ($status) {
            'successful' => PaymentStatus::Successful,
            'failed' => PaymentStatus::Failed,
            default => PaymentStatus::Pending,
        };
    }

    protected function bearerFrom(?string $bearer): ?FeeBearer
    {
        return $bearer === null ? null : FeeBearer::tryFrom($bearer);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function scalar(array $data, string $key): string|int|float|null
    {
        $value = $data[$key] ?? null;

        return is_string($value) || is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function time(array $data, string $key): ?Carbon
    {
        $value = $this->string($data, $key);

        return $value === null ? null : Carbon::parse($value);
    }
}
