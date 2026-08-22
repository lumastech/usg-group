<?php

namespace App\Console\Commands;

use App\Domain\Payments\CollectionRequest;
use App\Domain\Payments\Lenco\LencoGateway;
use App\Domain\Payments\Lenco\LencoOperator;
use App\Domain\Payments\PaymentGateway;
use App\Enums\MobileMoneyOperator;
use App\Exceptions\PaymentGatewayException;
use App\Support\Kwacha;
use Illuminate\Console\Command;

/**
 * Drives the provider's own sandbox numbers end to end.
 *
 * The point is to prove the integration against a real server before live keys exist,
 * using the accounts the provider publishes for the purpose: one that succeeds, one
 * with no money in it, one that times out. Each is asked for K1 and the answer is
 * printed as this application understood it, so a mistranslated status shows up here
 * rather than on a trading day.
 *
 * Refuses to run against anything but a sandbox base URL. This asks real people's
 * wallets for money.
 */
class LencoSmoke extends Command
{
    protected $signature = 'unity:lenco-smoke
        {--phone=* : Ask specific numbers instead of the published sandbox set}
        {--kwacha=1 : How much to ask each number for}';

    protected $description = 'Ask the provider\'s sandbox accounts for money and print what came back';

    /** The accounts the provider publishes for sandbox testing. */
    protected const SANDBOX = [
        ['0971111111', MobileMoneyOperator::Airtel, 'should succeed'],
        ['0975555555', MobileMoneyOperator::Airtel, 'should fail — not enough funds'],
        ['0977777777', MobileMoneyOperator::Airtel, 'should fail — timed out'],
        ['0961111111', MobileMoneyOperator::Mtn, 'should succeed'],
    ];

    public function handle(PaymentGateway $gateway): int
    {
        if (! $gateway instanceof LencoGateway) {
            $this->components->error('The Lenco gateway is not the one bound. Set PAYMENT_GATEWAY=lenco.');

            return self::FAILURE;
        }

        $baseUrl = (string) config('payments.gateways.lenco.base_url');

        if (! str_contains($baseUrl, 'sandbox')) {
            $this->components->error(
                "This asks real wallets for money. Point LENCO_BASE_URL at a sandbox first ({$baseUrl})."
            );

            return self::FAILURE;
        }

        $amount = Kwacha::of((int) $this->option('kwacha'));
        $rows = [];

        foreach ($this->accounts() as [$phone, $operator, $expected]) {
            $reference = 'smoke-'.now()->format('YmdHis').'-'.substr($phone, -4);

            try {
                $result = $gateway->collect(new CollectionRequest(
                    reference: $reference,
                    amountNgwee: Kwacha::toNgwee($amount),
                    phone: $phone,
                    operator: $operator,
                ));

                $rows[] = [
                    $phone,
                    $operator->value,
                    $expected,
                    $result->status->label(),
                    $result->reasonForFailure ?? '—',
                ];
            } catch (PaymentGatewayException $exception) {
                $rows[] = [$phone, $operator->value, $expected, 'refused', $exception->reason()];
            }
        }

        $this->table(['Phone', 'Network', 'Expected', 'Got', 'Reason'], $rows);

        $this->components->info(
            'Mobile money is authorised on the handset, so most of these will read as awaiting '
                .'authorisation. Run `unity:poll-payments --force` to see where they end up.'
        );

        return self::SUCCESS;
    }

    /** @return array<int, array{0: string, 1: MobileMoneyOperator, 2: string}> */
    protected function accounts(): array
    {
        /** @var array<int, string> $given */
        $given = $this->option('phone');

        if ($given === []) {
            return self::SANDBOX;
        }

        return array_map(
            fn (string $phone): array => [
                $phone,
                LencoOperator::forPhone($phone) ?? MobileMoneyOperator::Airtel,
                'as given',
            ],
            $given,
        );
    }
}
