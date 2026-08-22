<?php

namespace App\Domain\Payments;

use App\Enums\MobileMoneyOperator;
use Carbon\CarbonInterface;

/**
 * The seam a payment provider plugs into.
 *
 * Everything the application knows about moving money is expressed here. Nothing
 * outside App\Domain\Payments\Lenco may name Lenco, read a provider status string or
 * see a decimal amount — which is what makes changing provider a new class and one
 * config value, exactly as the SMS gateway is.
 *
 * Implementations throw App\Exceptions\PaymentGatewayException when the provider
 * refuses a request or cannot be reached. A refusal is not the same as a failed
 * payment: a payment that was accepted and then failed comes back as a PaymentResult
 * with a Failed status, because that one has money history behind it.
 */
interface PaymentGateway
{
    /*
    |--------------------------------------------------------------------------
    | Money in
    |--------------------------------------------------------------------------
    */

    /**
     * Ask a member's mobile money wallet for money.
     *
     * Authorised on the handset, so the usual answer is AwaitingAuthorization and
     * nothing may be posted yet.
     */
    public function collect(CollectionRequest $request): PaymentResult;

    /** Ask the provider where a collection got to. The poller's only question. */
    public function collectionStatus(string $reference): PaymentResult;

    /*
    |--------------------------------------------------------------------------
    | Money out
    |--------------------------------------------------------------------------
    */

    public function transfer(TransferRequest $request): PaymentResult;

    public function transferStatus(string $reference): PaymentResult;

    /*
    |--------------------------------------------------------------------------
    | Accounts
    |--------------------------------------------------------------------------
    */

    /** Whose account is this? Asked before the group agrees to pay anybody. */
    public function resolveBankAccount(string $accountNumber, string $bankId): ResolvedAccount;

    public function resolveMobileMoney(string $phone, MobileMoneyOperator $operator): ResolvedAccount;

    /** @return array<int, BankOption> */
    public function banks(): array;

    /** What the group has available to send, in ngwee. */
    public function balanceNgwee(): int;

    /**
     * Everything the provider recorded in a window, for reconciliation.
     *
     * Deliberately the collection and transfer listings rather than the account's
     * transaction feed: only these carry the reference we minted, and matching money on
     * amounts and dates alone is how a reconciliation quietly pairs the wrong two rows.
     *
     * @return array<int, PaymentResult>
     */
    public function collectionsBetween(CarbonInterface $from, CarbonInterface $to): array;

    /** @return array<int, PaymentResult> */
    public function transfersBetween(CarbonInterface $from, CarbonInterface $to): array;

    /**
     * What the browser needs to open the provider's hosted payment widget, or null
     * where the gateway has no such thing.
     *
     * Cards only ever go through this: entering a card into our own forms would put
     * the group inside PCI scope, which is not a thing thirty people in a village
     * banking group are going to certify against.
     *
     * @return array{key: string, script: string, channels: array<int, string>}|null
     */
    public function widgetConfig(): ?array;
}
