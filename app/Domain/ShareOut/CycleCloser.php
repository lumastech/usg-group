<?php

namespace App\Domain\ShareOut;

use App\Domain\Approvals\TwoPersonRule;
use App\Domain\Support\MoneyMutator;
use App\Enums\CycleStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Cycle;
use App\Models\Member;
use Illuminate\Support\Carbon;

/**
 * Walks a cycle from Active to the day the money is divided.
 *
 * Closing and ShareOut are two states on purpose. Closing stops new lending and puts
 * the committee on the pre-flight checklist; ShareOut is what a clean checklist opens,
 * and it is the only state in which PayoutExecutor will settle anybody. Skipping
 * straight from Active would hand the treasurer a payout button over a month nobody
 * has finished posting.
 *
 * The checklist is re-run here rather than trusted from the screen: minutes pass
 * between looking at it and signing for it, and a repayment landing in between must
 * not be shared out as if it had never arrived.
 */
class CycleCloser
{
    public function __construct(
        protected ShareOutPreflight $preflight,
        protected TwoPersonRule $twoPersonRule,
        protected MoneyMutator $mutator,
    ) {}

    /** Active → Closing. Lending stops and the checklist begins. */
    public function beginClosing(Cycle $cycle, Member $actor): Cycle
    {
        if ($cycle->status !== CycleStatus::Active) {
            throw DomainRuleException::make(
                "The {$cycle->name} cycle is {$cycle->status->label()}, so there is nothing to close."
            );
        }

        return $this->transition($cycle, $actor, CycleStatus::Closing, [
            'Closed the '.$cycle->name.' cycle to new lending and opened the share-out checklist',
        ]);
    }

    /**
     * Closing → ShareOut, which is what lets members be paid.
     *
     * A clean checklist opens it on the strength of `cycles.manage` alone. A dirty one
     * may still be overridden, but the constitution treats that as a committee
     * decision rather than an administrative one: it costs a written reason and a
     * second committee signature, and both are logged against the transition.
     */
    public function openShareOut(
        Cycle $cycle,
        Member $actor,
        ?Member $secondApprover = null,
        ?string $overrideNote = null,
    ): Cycle {
        if ($cycle->status === CycleStatus::ShareOut) {
            throw DomainRuleException::make("The {$cycle->name} cycle is already sharing out.");
        }

        if ($cycle->status !== CycleStatus::Closing) {
            throw DomainRuleException::make(
                "The {$cycle->name} cycle is {$cycle->status->label()}. Close it to new lending and work the "
                .'pre-flight checklist before opening share-out.'
            );
        }

        $blocking = array_values(array_filter(
            $this->preflight->items($cycle),
            fn (PreflightItem $item): bool => ! $item->passed,
        ));

        if ($blocking !== []) {
            $this->assertOverridable($blocking, $secondApprover, $overrideNote);
            $this->twoPersonRule->assertDistinctCommittee($actor, $secondApprover);
        }

        return $this->transition($cycle, $actor, CycleStatus::ShareOut, [
            $blocking === []
                ? 'Opened share-out on the '.$cycle->name.' cycle with a clean pre-flight checklist'
                : 'Opened share-out on the '.$cycle->name.' cycle, overriding '
                    .count($blocking).' outstanding pre-flight check(s)',
        ], [
            'overridden' => $blocking !== [],
            'overridden_checks' => array_map(fn (PreflightItem $item): string => $item->key, $blocking),
            'override_note' => $overrideNote,
            'second_approver_member_id' => $secondApprover?->id,
        ]);
    }

    /**
     * @param  array<int, PreflightItem>  $blocking
     */
    protected function assertOverridable(array $blocking, ?Member $secondApprover, ?string $overrideNote): void
    {
        $names = implode(', ', array_map(fn (PreflightItem $item): string => $item->label, $blocking));

        if (blank($overrideNote)) {
            throw DomainRuleException::make(
                "The pre-flight checklist is not clear ({$names}). Opening share-out anyway is a committee "
                .'override and needs a written reason.'
            );
        }

        if ($secondApprover === null) {
            throw DomainRuleException::make(
                'Overriding the pre-flight checklist needs a second committee member to confirm it.'
            );
        }
    }

    /**
     * @param  array<int, string>  $reason
     * @param  array<string, mixed>  $context
     */
    protected function transition(Cycle $cycle, Member $actor, CycleStatus $to, array $reason, array $context = []): Cycle
    {
        $from = $cycle->status;

        return $this->mutator->mutate(
            $actor,
            $reason[0],
            function () use ($cycle, $to): Cycle {
                $cycle->forceFill(['status' => $to])->save();

                return $cycle->refresh();
            },
            $context + [
                'cycle_id' => $cycle->id,
                'from' => $from->value,
                'to' => $to->value,
                'transitioned_at' => Carbon::now()->toIso8601String(),
            ],
        );
    }
}
