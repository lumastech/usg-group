<?php

namespace App\Domain\Payments;

use App\Enums\PaymentStatus;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\PaymentIntent;
use App\Models\PaymentReconciliation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Compares what the provider moved against what the group's books took.
 *
 * Webhooks miss, pollers give up, and a payment that succeeded while this server was
 * down leaves no trace on our side at all. This is the net under all of that: once a
 * day, both sides are listed and anything appearing on only one of them is written
 * down for a person to look at.
 *
 * Deliberately reads the collection and transfer listings rather than the account's
 * transaction feed — only those carry the reference we minted, and matching money by
 * amount and date is how a reconciliation quietly pairs the wrong two rows.
 */
class Reconciler
{
    public function __construct(protected PaymentGateway $gateway) {}

    public function run(
        Cycle $cycle,
        CarbonInterface $from,
        CarbonInterface $to,
        ?Member $actor = null,
    ): PaymentReconciliation {
        $collections = collect($this->gateway->collectionsBetween($from, $to));
        $transfers = collect($this->gateway->transfersBetween($from, $to));

        $ours = $this->intentsBetween($from, $to);

        $providerRows = $collections->concat($transfers);

        $unmatched = [
            ...$this->providerSideGaps($providerRows, $ours),
            ...$this->ourSideGaps($providerRows, $ours),
        ];

        /*
         * One row per day, looked up on the date rather than through updateOrCreate:
         * the column is a date and the attribute is a datetime, and matching those two
         * as strings is a bug that only shows up on some drivers.
         */
        $reconciliation = PaymentReconciliation::query()
            ->acrossCycles()
            ->whereDate('for_date', $to)
            ->first() ?? new PaymentReconciliation;

        $reconciliation->fill([
            'for_date' => $to->toDateString(),
            'cycle_id' => $cycle->id,
            'collections_count' => $collections->filter(fn (PaymentResult $r): bool => $r->hasSucceeded())->count(),
            'collections_ngwee' => $this->sum($collections),
            'transfers_count' => $transfers->filter(fn (PaymentResult $r): bool => $r->hasSucceeded())->count(),
            'transfers_ngwee' => $this->sum($transfers),
            'fees_ngwee' => $this->fees($collections->concat($transfers)),
            'provider_balance_ngwee' => $this->balance(),
            'unmatched' => $unmatched,
            'unmatched_count' => count($unmatched),
            'run_by_member_id' => $actor?->id,
            'ran_at' => Carbon::now(),
        ])->save();

        return $reconciliation->refresh();
    }

    /**
     * Money the provider moved that this system has not recorded.
     *
     * @param  Collection<int, PaymentResult>  $providerRows
     * @param  Collection<string, PaymentIntent>  $ours
     * @return array<int, array<string, mixed>>
     */
    protected function providerSideGaps(Collection $providerRows, Collection $ours): array
    {
        $gaps = [];

        foreach ($providerRows as $row) {
            if (! $row->hasSucceeded()) {
                continue;
            }

            $reference = $row->providerReference;
            $intent = $reference === null ? null : $ours->get($reference);

            if ($intent === null) {
                $gaps[] = [
                    'side' => 'provider',
                    'reason' => 'The provider moved this money and this system has no record of it.',
                    'reference' => $reference,
                    'provider_id' => $row->providerId,
                    'amount_ngwee' => $row->amountNgwee,
                ];

                continue;
            }

            if ($intent->status !== PaymentStatus::Posted) {
                $gaps[] = [
                    'side' => 'both',
                    'reason' => 'The provider moved this money but the ledgers have not taken it: '
                        .$intent->status->label().'.',
                    'reference' => $reference,
                    'payment_intent_id' => $intent->id,
                    'amount_ngwee' => $row->amountNgwee,
                ];

                continue;
            }

            $recorded = $intent->amount_ngwee->getMinorAmount()->toInt();

            if ($row->amountNgwee !== null && $row->amountNgwee !== $recorded) {
                $gaps[] = [
                    'side' => 'both',
                    'reason' => 'The amount the provider moved does not match the amount recorded here.',
                    'reference' => $reference,
                    'payment_intent_id' => $intent->id,
                    'amount_ngwee' => $row->amountNgwee,
                    'recorded_ngwee' => $recorded,
                ];
            }
        }

        return $gaps;
    }

    /**
     * Money this system believes moved that the provider does not list.
     *
     * @param  Collection<int, PaymentResult>  $providerRows
     * @param  Collection<string, PaymentIntent>  $ours
     * @return array<int, array<string, mixed>>
     */
    protected function ourSideGaps(Collection $providerRows, Collection $ours): array
    {
        $seen = $providerRows
            ->map(fn (PaymentResult $row): ?string => $row->providerReference)
            ->filter()
            ->flip();

        $gaps = [];

        foreach ($ours as $intent) {
            if (! $intent->status->hasSucceeded() || $seen->has($intent->reference)) {
                continue;
            }

            $gaps[] = [
                'side' => 'ours',
                'reason' => 'This system recorded money the provider does not list for the period.',
                'reference' => $intent->reference,
                'payment_intent_id' => $intent->id,
                'amount_ngwee' => $intent->amount_ngwee->getMinorAmount()->toInt(),
            ];
        }

        return $gaps;
    }

    /** @return Collection<string, PaymentIntent> */
    protected function intentsBetween(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return PaymentIntent::query()
            ->acrossCycles()
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->get()
            ->keyBy('reference');
    }

    /** @param Collection<int, PaymentResult> $rows */
    protected function sum(Collection $rows): int
    {
        return $rows
            ->filter(fn (PaymentResult $row): bool => $row->hasSucceeded())
            ->sum(fn (PaymentResult $row): int => $row->amountNgwee ?? 0);
    }

    /** @param Collection<int, PaymentResult> $rows */
    protected function fees(Collection $rows): int
    {
        return $rows->sum(fn (PaymentResult $row): int => $row->feeNgwee ?? 0);
    }

    /** The balance is context, not a verdict — a provider that is down should not fail the run. */
    protected function balance(): ?int
    {
        try {
            return $this->gateway->balanceNgwee();
        } catch (\Throwable) {
            return null;
        }
    }
}
