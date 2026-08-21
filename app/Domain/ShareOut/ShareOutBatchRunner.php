<?php

namespace App\Domain\ShareOut;

use App\Domain\Payouts\PayoutCalculator;
use App\Domain\Payouts\PayoutExecutor;
use App\Enums\CycleStatus;
use App\Enums\MemberStatus;
use App\Enums\PayoutCase;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\Payout;
use App\Support\Kwacha;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Settles the whole room on the last day.
 *
 * Thirty closures signed one at a time is thirty chances to lose one, so the runner
 * walks every member still standing at share-out and puts each through the same
 * PayoutExecutor a single closure goes through. Nothing is short-circuited: the
 * ledgers are re-read per member, the two signatures are checked per member, and the
 * freeze is applied per member.
 *
 * Each settlement is its own transaction, so one member who cannot be settled — an
 * estate with no agreed terms, a loan still running — is reported and stepped over
 * rather than rolling back the twenty-nine already signed for. That is deliberate:
 * the group is standing in a room with cash on the table, and an all-or-nothing batch
 * would strand them.
 *
 * Exits (left early, expelled, deceased) are NOT swept up here. Those are settled one
 * at a time on the closures screen, because each carries a conversation the batch has
 * no way of having.
 */
class ShareOutBatchRunner
{
    public function __construct(
        protected PayoutCalculator $calculator,
        protected PayoutExecutor $executor,
    ) {}

    /**
     * Who the runner would settle, and what each would come to. Writes nothing.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function preview(Cycle $cycle): Collection
    {
        return $this->candidates($cycle)->map(function (Member $member): array {
            $breakdown = $this->calculator->using($member, PayoutCase::ActiveShareOut);

            return [
                'member_id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'net_value_ngwee' => $breakdown->netValueNgwee,
                'round_off_ngwee' => $breakdown->roundOffNgwee,
                'net_payable_ngwee' => $breakdown->netPayableNgwee,
                'payable_ngwee' => $breakdown->payableNgwee(),
                'shortfall_ngwee' => $breakdown->shortfallNgwee(),
                'is_negative' => $breakdown->isNegative(),
            ];
        })->values();
    }

    /**
     * Settles every member the preview listed.
     *
     * @param  array<string, mixed>  $context  passed through to each closure, e.g. a batch note
     * @return array{
     *     settled: array<int, array<string, mixed>>,
     *     skipped: array<int, array<string, mixed>>,
     *     settled_count: int,
     *     skipped_count: int,
     *     paid_ngwee: int,
     *     shortfall_ngwee: int,
     * }
     */
    public function run(Cycle $cycle, Member $actor, Member $secondApprover, array $context = []): array
    {
        $this->assertSharingOut($cycle);

        $settled = [];
        $skipped = [];

        foreach ($this->candidates($cycle) as $member) {
            try {
                $record = $this->executor->execute($member, $actor, $secondApprover, $context);
            } catch (DomainRuleException $exception) {
                $skipped[] = [
                    'member_id' => $member->id,
                    'member_number' => $member->member_number,
                    'full_name' => $member->full_name,
                    'reason' => $exception->getMessage(),
                ];

                continue;
            }

            $settled[] = [
                'member_id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'payout_id' => $record instanceof Payout ? $record->id : null,
                'record' => class_basename($record),
                'amount_ngwee' => $record instanceof Payout
                    ? Kwacha::toNgwee($record->amount_ngwee)
                    : 0,
                'shortfall_ngwee' => $record instanceof Payout
                    ? 0
                    : Kwacha::toNgwee($record->amount_owed_ngwee),
            ];
        }

        return [
            'settled' => $settled,
            'skipped' => $skipped,
            'settled_count' => count($settled),
            'skipped_count' => count($skipped),
            'paid_ngwee' => array_sum(array_column($settled, 'amount_ngwee')),
            'shortfall_ngwee' => array_sum(array_column($settled, 'shortfall_ngwee')),
        ];
    }

    /**
     * The master schedule the signatories sign at the bank: one line per payout, in
     * member-number order, with the batch's total at the foot.
     *
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     total_ngwee: int,
     *     count: int,
     * }
     */
    public function schedule(Cycle $cycle): array
    {
        $rows = Payout::query()
            ->acrossCycles()
            ->where('cycle_id', $cycle->id)
            ->with('member')
            ->get()
            ->sortBy(fn (Payout $payout): int => $payout->member?->member_number ?? PHP_INT_MAX)
            ->map(fn (Payout $payout): array => [
                'payout_id' => $payout->id,
                'member_id' => $payout->member_id,
                'member_number' => $payout->member?->member_number,
                'full_name' => $payout->member?->full_name,
                'case' => $payout->case->value,
                'case_label' => $payout->case->label(),
                'net_value_ngwee' => Kwacha::toNgwee($payout->net_value_ngwee),
                'round_off_ngwee' => Kwacha::toNgwee($payout->round_off_ngwee),
                'amount_ngwee' => Kwacha::toNgwee($payout->amount_ngwee),
                'executed_at' => $payout->executed_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'total_ngwee' => array_sum(array_column($rows, 'amount_ngwee')),
            'count' => count($rows),
        ];
    }

    /**
     * Members still standing at the end of the cycle who have not yet been settled.
     *
     * @return EloquentCollection<int, Member>
     */
    public function candidates(Cycle $cycle): EloquentCollection
    {
        return $cycle->members()
            ->where('status', MemberStatus::Active->value)
            ->whereNull('ledgers_frozen_at')
            ->get();
    }

    protected function assertSharingOut(Cycle $cycle): void
    {
        if ($cycle->status !== CycleStatus::ShareOut) {
            throw DomainRuleException::make(
                "The {$cycle->name} cycle is {$cycle->status->label()}. Open share-out before running the batch."
            );
        }
    }
}
