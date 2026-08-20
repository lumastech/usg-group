<?php

namespace App\Domain\Reporting;

use App\Domain\SocialFund\SocialFundContributions;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\SocialFundTransactionType;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Illuminate\Support\Collection;

/**
 * Everything the Social Fund dashboard reads, computed in one place.
 *
 * The screen, the export and the member's own view all take their figures from here,
 * so the balance on the dashboard and the balance at the foot of the ledger are the
 * same number by construction rather than by coincidence.
 */
class SocialFundOverview
{
    public function __construct(
        protected SocialFundLedger $ledger,
        protected SocialFundContributions $contributions,
    ) {}

    /**
     * @return array{
     *     balance_ngwee: int,
     *     inflow_ngwee: int,
     *     outflow_ngwee: int,
     *     contribution_ngwee: int,
     *     expected_contribution_ngwee: int,
     *     contributions_paid: int,
     *     contributions_expected: int,
     *     months: array<int, array{id: int, label: string, short_label: string, in_ngwee: int, out_ngwee: int, closing_ngwee: int}>,
     *     by_type: array<int, array{type: string, label: string, total_ngwee: int}>
     * }
     */
    public function for(Cycle $cycle): array
    {
        $entries = $this->ledger->entries($cycle)->get();
        $outstanding = $this->contributions->outstanding($cycle);
        $activeMembers = $cycle->members()->where('status', 'active')->count();

        $signed = fn (Collection $rows): int => (int) $rows->sum(
            fn (SocialFundTransaction $entry): int => $entry->getRawOriginal('amount_ngwee')
        );

        $inflow = $signed($entries->filter(fn (SocialFundTransaction $e): bool => $e->getRawOriginal('amount_ngwee') > 0));
        $outflow = $signed($entries->filter(fn (SocialFundTransaction $e): bool => $e->getRawOriginal('amount_ngwee') < 0));

        return [
            'balance_ngwee' => $inflow + $outflow,
            'inflow_ngwee' => $inflow,
            'outflow_ngwee' => abs($outflow),
            'contribution_ngwee' => $signed($entries->where('type', SocialFundTransactionType::Contribution)),
            'expected_contribution_ngwee' => Kwacha::toNgwee($cycle->social_fund_contribution_ngwee) * $activeMembers,
            'contributions_paid' => $activeMembers - $outstanding->count(),
            'contributions_expected' => $activeMembers,
            'months' => $this->months($cycle, $entries),
            'by_type' => $this->byType($entries),
        ];
    }

    /**
     * Active members who have not paid, with their contact details for the chaser list.
     *
     * @return array<int, array{member_id: int, member_number: int, full_name: string, phone: string|null, is_diaspora: bool}>
     */
    public function unpaidContributions(Cycle $cycle): array
    {
        return $this->contributions->outstanding($cycle)
            ->map(fn (Member $member): array => [
                'member_id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'phone' => $member->phone,
                'is_diaspora' => $member->is_diaspora,
            ])->values()->all();
    }

    /**
     * Money in and out per month of the cycle, with a running closing balance.
     *
     * @param  Collection<int, SocialFundTransaction>  $entries
     * @return array<int, array{id: int, label: string, short_label: string, in_ngwee: int, out_ngwee: int, closing_ngwee: int}>
     */
    protected function months(Cycle $cycle, Collection $entries): array
    {
        $running = 0;

        return $cycle->months->map(function (CycleMonth $month) use ($entries, &$running): array {
            $inMonth = $entries->where('cycle_month_id', $month->id);

            $in = (int) $inMonth->sum(fn (SocialFundTransaction $e): int => max(0, $e->getRawOriginal('amount_ngwee')));
            $out = (int) $inMonth->sum(fn (SocialFundTransaction $e): int => min(0, $e->getRawOriginal('amount_ngwee')));

            $running += $in + $out;

            return [
                'id' => $month->id,
                'label' => $month->label(),
                'short_label' => $month->month->format('M'),
                'in_ngwee' => $in,
                'out_ngwee' => abs($out),
                'closing_ngwee' => $running,
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int, SocialFundTransaction>  $entries
     * @return array<int, array{type: string, label: string, total_ngwee: int}>
     */
    protected function byType(Collection $entries): array
    {
        return collect(SocialFundTransactionType::cases())
            ->map(fn (SocialFundTransactionType $type): array => [
                'type' => $type->value,
                'label' => $type->label(),
                'total_ngwee' => (int) $entries->where('type', $type)
                    ->sum(fn (SocialFundTransaction $e): int => $e->getRawOriginal('amount_ngwee')),
            ])
            ->reject(fn (array $row): bool => $row['total_ngwee'] === 0)
            ->values()
            ->all();
    }
}
