<?php

namespace App\Domain\Payouts;

use App\Enums\MemberStatus;
use App\Enums\PayoutCase;
use App\Models\Cycle;
use App\Models\FuneralGrantClaim;
use App\Models\Member;
use App\Support\Kwacha;
use Illuminate\Support\Collection;

/**
 * Who is waiting to be closed out, and who already has been.
 *
 * A read model for the closures screen. It computes each member's breakdown without
 * writing anything, so the committee can see what a settlement would come to — and
 * whether it comes out negative — long before anyone signs for it.
 */
class ClosureRegister
{
    public function __construct(protected PayoutCalculator $calculator) {}

    /**
     * Every member of the cycle who still needs settling, exits first.
     *
     * Departures are listed ahead of the members still standing, because a family
     * waiting on a death settlement should not be at the bottom of a list of thirty.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pending(Cycle $cycle): Collection
    {
        return $this->rowsFor($cycle, settled: false);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function settled(Cycle $cycle): Collection
    {
        return $this->rowsFor($cycle, settled: true);
    }

    /**
     * One member's row, with their computed position.
     *
     * @return array<string, mixed>
     */
    public function row(Member $member): array
    {
        $breakdown = $this->calculator->for($member);

        return [
            'member_id' => $member->id,
            'member_number' => $member->member_number,
            'full_name' => $member->full_name,
            'status' => $member->status,
            'status_label' => $member->status->label(),
            'case' => PayoutCase::forStatus($member->status),
            'case_label' => PayoutCase::forStatus($member->status)->label(),
            'date_of_death' => $member->date_of_death?->toDateString(),
            'status_effective_on' => $member->status_effective_on?->toDateString(),
            'net_value_ngwee' => $breakdown->netValueNgwee,
            'round_off_ngwee' => $breakdown->roundOffNgwee,
            'net_payable_ngwee' => $breakdown->netPayableNgwee,
            'is_negative' => $breakdown->isNegative(),
            'settled' => $member->ledgersFrozen(),
            'settled_at' => $member->ledgers_frozen_at?->toIso8601String(),
            'funeral_grant' => $this->funeralGrant($member),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    protected function rowsFor(Cycle $cycle, bool $settled): Collection
    {
        return $cycle->members()
            ->when($settled, fn ($query) => $query->whereNotNull('ledgers_frozen_at'))
            ->when(! $settled, fn ($query) => $query->whereNull('ledgers_frozen_at'))
            ->get()
            ->sortBy(fn (Member $member): array => [
                $member->status === MemberStatus::Active ? 1 : 0,
                $member->member_number,
            ])
            ->values()
            ->map(fn (Member $member): array => $this->row($member));
    }

    /**
     * The funeral grant claim standing against this member's name, if there is one.
     *
     * The grant is the fund's business, not the payout's, so it is never netted into
     * the breakdown — the closure screen shows it beside the statement so the committee
     * can see that both matters have been dealt with before they sign.
     *
     * @return array{id: int, status: string, status_label: string, amount_ngwee: int}|null
     */
    protected function funeralGrant(Member $member): ?array
    {
        if ($member->status !== MemberStatus::Deceased) {
            return null;
        }

        $claim = FuneralGrantClaim::query()
            ->acrossCycles()
            ->where('member_id', $member->id)
            ->latest('claim_date')
            ->first();

        return $claim === null ? null : [
            'id' => $claim->id,
            'status' => $claim->status->value,
            'status_label' => $claim->status->label(),
            'amount_ngwee' => Kwacha::toNgwee($claim->amount_ngwee),
        ];
    }
}
