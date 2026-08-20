<?php

namespace App\Domain\Governance;

use App\Enums\CommitteeRole;
use App\Enums\MemberStatus;
use App\Enums\TermEndReason;
use App\Exceptions\CommitteeSeatTakenException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\MemberNotActiveException;
use App\Exceptions\NoticePeriodNotServedException;
use App\Models\CommitteeTerm;
use App\Models\Cycle;
use App\Models\Member;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Putting a member into office, and taking them out of it.
 *
 * Every rule the constitution attaches to office is enforced here rather than in a
 * controller: one year at a time, one holder per office, a month's notice before a
 * resignation bites. The portal role follows the term in the same transaction, so
 * there is never a moment where the register and the permissions disagree.
 */
class CommitteeTermService
{
    /** The constitution's term limit: one year, then the group votes again. */
    public const TERM_MONTHS = 12;

    /** How long an officer serves on after giving notice, unless it is waived. */
    public const NOTICE_MONTHS = 1;

    public function __construct(protected CommitteeRoleSync $roles) {}

    /**
     * Records a member taking office.
     *
     * Signatory is the one office the group may fill more than once — the bank asks
     * for several — so only the four executive offices are held to a single holder.
     */
    public function appoint(
        Member $member,
        CommitteeRole $role,
        Cycle $cycle,
        ?CarbonInterface $startedAt = null,
    ): CommitteeTerm {
        $startedAt = ($startedAt ?? now())->copy()->startOfDay();

        if ($member->status !== MemberStatus::Active) {
            throw new MemberNotActiveException(
                "{$member->full_name} is not an active member and cannot hold office.",
            );
        }

        return DB::transaction(function () use ($member, $role, $cycle, $startedAt): CommitteeTerm {
            $this->assertOfficeVacant($role, $cycle, $member);
            $this->assertMemberFree($member, $role, $cycle);

            $term = CommitteeTerm::create([
                'cycle_id' => $cycle->id,
                'member_id' => $member->id,
                'role' => $role,
                'started_at' => $startedAt,
            ]);

            $this->roles->syncMember($member->refresh());

            $this->log(
                $term,
                "{$member->full_name} took office as {$role->label()}",
            );

            return $term;
        });
    }

    /**
     * Ends a term, for whatever reason took the officer out of the seat.
     *
     * A resignation is the only ending with arithmetic behind it, so it has its own
     * entry point below; this one handles a term simply running out and a removal.
     */
    public function end(
        CommitteeTerm $term,
        TermEndReason $reason,
        ?CarbonInterface $endedAt = null,
    ): CommitteeTerm {
        $endedAt = ($endedAt ?? now())->copy()->startOfDay();

        if (! $term->isCurrent()) {
            throw DomainRuleException::make('That term has already ended.');
        }

        if ($endedAt->lt($term->started_at)) {
            throw DomainRuleException::make('A term cannot end before it started.');
        }

        return DB::transaction(function () use ($term, $reason, $endedAt): CommitteeTerm {
            $term->forceFill(['ended_at' => $endedAt, 'end_reason' => $reason])->save();

            $this->roles->syncMember($term->member->refresh());

            $this->log(
                $term,
                "{$term->member->full_name}'s term as {$term->role->label()} ended ({$reason->label()})",
            );

            return $term->refresh();
        });
    }

    /**
     * Ends a term on notice.
     *
     * The month runs from the day notice was given, not the day it was typed in, so a
     * notice recorded late still ends on the date the group was actually told about.
     * Month arithmetic here must not overflow: notice on 31 January runs to the end of
     * February, not into 3 March, because a month's notice is a month and never more.
     * The committee may waive the wait, but only in writing — the note is required,
     * because a waiver has to be explicable at the next meeting.
     */
    public function resign(
        CommitteeTerm $term,
        CarbonInterface $noticeDate,
        ?CarbonInterface $endedAt = null,
        ?string $waiverNote = null,
    ): CommitteeTerm {
        $noticeDate = $noticeDate->copy()->startOfDay();
        $endedAt = ($endedAt ?? $noticeDate->copy()->addMonthsNoOverflow(self::NOTICE_MONTHS))->copy()->startOfDay();

        $earliest = $noticeDate->copy()->addMonthsNoOverflow(self::NOTICE_MONTHS);

        if ($endedAt->lt($earliest) && blank($waiverNote)) {
            throw new NoticePeriodNotServedException(sprintf(
                'A month\'s notice runs to %s. Ending earlier needs a written waiver from the committee.',
                $earliest->format('j M Y'),
            ));
        }

        $term->forceFill([
            'resignation_notice_date' => $noticeDate,
            'notice_waiver_note' => $endedAt->lt($earliest) ? $waiverNote : null,
        ])->save();

        return $this->end($term, TermEndReason::Resigned, $endedAt);
    }

    /**
     * Removes a member from every office they hold, after a no-confidence motion.
     *
     * Called by MotionRecorder when the vote carries, never from a controller: the
     * removal is a consequence of the tally, not a separate decision somebody makes.
     *
     * @return array<int, CommitteeTerm>
     */
    public function removeFromOffice(Member $member, ?CarbonInterface $on = null): array
    {
        $terms = CommitteeTerm::query()
            ->forCycle($member->cycle_id)
            ->current()
            ->where('member_id', $member->id)
            ->get();

        return $terms
            ->map(fn (CommitteeTerm $term): CommitteeTerm => $this->end($term, TermEndReason::Removed, $on))
            ->all();
    }

    /** The day a term reaches the constitution's one-year limit. */
    public function expiresOn(CommitteeTerm $term): CarbonInterface
    {
        return $term->started_at->copy()->addMonthsNoOverflow(self::TERM_MONTHS);
    }

    /** Whether a serving term has run past its year. */
    public function isOverdue(CommitteeTerm $term, ?CarbonInterface $at = null): bool
    {
        return $term->isCurrent() && ($at ?? now())->gte($this->expiresOn($term));
    }

    /** The terms being served right now, in the constitution's order of office. */
    /** @return Collection<int, CommitteeTerm> */
    public function current(Cycle $cycle): Collection
    {
        $order = array_flip(CommitteeRole::values());

        return CommitteeTerm::query()
            ->forCycle($cycle->id)
            ->current()
            ->with('member')
            ->get()
            ->sortBy(fn (CommitteeTerm $term): int => $order[$term->role->value])
            ->values();
    }

    protected function assertOfficeVacant(CommitteeRole $role, Cycle $cycle, Member $member): void
    {
        if ($role === CommitteeRole::Signatory) {
            return;
        }

        $holder = CommitteeTerm::query()
            ->forCycle($cycle->id)
            ->current()
            ->where('role', $role)
            ->with('member')
            ->first();

        if ($holder !== null) {
            throw new CommitteeSeatTakenException(sprintf(
                '%s is already serving as %s. End that term first.',
                $holder->member->full_name,
                $role->label(),
            ));
        }
    }

    /**
     * One member, one executive office.
     *
     * Signatory sits outside this: the bank asks for several, and the chairperson
     * signing cheques is the normal arrangement rather than a conflict. Holding two
     * of the four executive offices at once is not, so it is refused.
     */
    protected function assertMemberFree(Member $member, CommitteeRole $role, Cycle $cycle): void
    {
        $existing = CommitteeTerm::query()
            ->forCycle($cycle->id)
            ->current()
            ->where('member_id', $member->id)
            ->get();

        $clash = $existing->first(fn (CommitteeTerm $term): bool => $term->role === $role
            || ($role !== CommitteeRole::Signatory && $term->role !== CommitteeRole::Signatory));

        if ($clash !== null) {
            throw new CommitteeSeatTakenException(sprintf(
                '%s is already serving as %s.',
                $member->full_name,
                $clash->role->label(),
            ));
        }
    }

    protected function log(CommitteeTerm $term, string $message): void
    {
        activity('governance')
            ->performedOn($term)
            ->withProperties([
                'member_id' => $term->member_id,
                'role' => $term->role->value,
                'started_at' => $term->started_at->toDateString(),
                'ended_at' => $term->ended_at?->toDateString(),
                'end_reason' => $term->end_reason?->value,
            ])
            ->event('governance.term')
            ->log($message);
    }
}
