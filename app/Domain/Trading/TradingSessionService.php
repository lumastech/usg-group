<?php

namespace App\Domain\Trading;

use App\Domain\Declarations\DeclarationService;
use App\Domain\Loans\LoanDisbursementQueue;
use App\Enums\CycleMonthStatus;
use App\Enums\TradingSessionStatus;
use App\Exceptions\DomainRuleException;
use App\Exceptions\TradingSessionClosedException;
use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Models\Loan;
use App\Models\Member;
use App\Models\TradingEntry;
use App\Models\TradingSession;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The trading day's worksheet: opening it, and marking money across the table.
 *
 * Opening a session is the act that closes the declaration window — every declaration
 * is locked, and each one becomes a row on the sheet carrying what the member said
 * they would bring and what the disbursement queue says they are owed. From there the
 * treasurer only marks what actually happened; nothing reaches a ledger until the
 * session is concluded.
 */
class TradingSessionService
{
    public function __construct(
        protected DeclarationService $declarations,
        protected LoanDisbursementQueue $queue,
    ) {}

    /** The month's session, or null when the window has not closed yet. */
    public function find(CycleMonth $month): ?TradingSession
    {
        return TradingSession::query()->where('cycle_month_id', $month->id)->first();
    }

    /**
     * Opens the month's session, locking its declarations and laying out the sheet.
     *
     * Idempotent: a session that already exists is returned with its entries brought
     * back in line with the declarations and the queue, so a late declaration captured
     * by the treasurer appears on the sheet without anything being rebuilt by hand.
     */
    public function openFor(CycleMonth $month): TradingSession
    {
        return DB::transaction(function () use ($month): TradingSession {
            $session = $this->find($month) ?? TradingSession::create([
                'cycle_id' => $month->cycle_id,
                'cycle_month_id' => $month->id,
                'scheduled_conclude_date' => $month->trading_concludes_on,
                'status' => TradingSessionStatus::Open,
            ]);

            if (! $session->isOpen()) {
                return $session;
            }

            $this->declarations->lockMonth($month);
            $this->syncEntries($session);

            $month->forceFill(['status' => CycleMonthStatus::Trading])->save();

            return $session->refresh();
        });
    }

    /**
     * Brings the sheet in line with the declarations and the disbursement queue.
     *
     * Expected figures are always recomputed; anything the treasurer has already marked
     * — the money received, the time it arrived — is left exactly as they marked it.
     */
    public function syncEntries(TradingSession $session): void
    {
        $month = $session->cycleMonth;

        $declarations = Declaration::query()
            ->forMonth($month)
            ->get()
            ->keyBy('member_id');

        $owed = $this->queuedDisbursements($month);

        $memberIds = $declarations->keys()
            ->merge($owed->keys())
            ->merge($session->entries()->pluck('member_id'))
            ->unique();

        foreach ($memberIds as $memberId) {
            /** @var Declaration|null $declaration */
            $declaration = $declarations->get($memberId);

            $entry = TradingEntry::firstOrNew([
                'trading_session_id' => $session->id,
                'member_id' => $memberId,
            ]);

            $expectedIn = $declaration?->expectedInNgwee() ?? 0;
            $received = $entry->exists ? $entry->getRawOriginal('actual_in_ngwee') : 0;

            $entry->fill([
                'declaration_id' => $declaration?->id,
                'expected_in_ngwee' => Kwacha::ofNgwee($expectedIn),
                'expected_out_ngwee' => Kwacha::ofNgwee((int) $owed->get($memberId, 0)),
                'variance_ngwee' => Kwacha::ofNgwee($received - $expectedIn),
            ]);

            /* A row the treasurer has not touched yet mirrors the declaration's split;
               one they have already marked keeps the split that marking produced. */
            if ($entry->received_at === null) {
                $entry->fill([
                    'savings_portion_ngwee' => Kwacha::ofNgwee($declaration?->getRawOriginal('saving_amount_ngwee') ?? 0),
                    'repayment_portion_ngwee' => Kwacha::ofNgwee($declaration?->getRawOriginal('loan_repayment_amount_ngwee') ?? 0),
                ]);
            }

            $entry->save();
        }
    }

    /**
     * Marks money received at the table.
     *
     * The time it arrived is what drives the penalty, so it is captured rather than
     * assumed: a payment handed over on the 9th when trading concluded on the 7th is
     * two days late whatever day the treasurer types it in.
     */
    public function markReceived(
        TradingEntry $entry,
        Money $actual,
        CarbonInterface $receivedAt,
        Member $actor,
    ): TradingEntry {
        $this->assertOpen($entry->session);

        $declaration = $entry->declaration;
        $ngwee = Kwacha::toNgwee($actual);

        /*
         * A short payment covers the savings first and the loan second. Savings are the
         * member's own money coming back to them at share-out; the repayment is money
         * owed to everybody, and letting a shortfall land there is the group's loss to
         * record rather than the individual's to choose.
         */
        $savings = min($ngwee, $declaration?->getRawOriginal('saving_amount_ngwee') ?? $ngwee);

        $entry->forceFill([
            'actual_in_ngwee' => $ngwee,
            'received_at' => $receivedAt,
            'savings_portion_ngwee' => $savings,
            'repayment_portion_ngwee' => $ngwee - $savings,
            'penalty_days' => $this->penaltyDays($entry->session, $receivedAt),
            'variance_ngwee' => $ngwee - $entry->getRawOriginal('expected_in_ngwee'),
        ])->save();

        activity('trading')
            ->causedBy($actor->user)
            ->performedOn($entry)
            ->withProperties(['actor_member_id' => $actor->id, 'amount_ngwee' => $ngwee])
            ->log('Marked '.Kwacha::format($actual)." received from {$entry->member->full_name}");

        return $entry->refresh();
    }

    /** Undoes a receipt marked in error, before the session is concluded. */
    public function clearReceipt(TradingEntry $entry): TradingEntry
    {
        $this->assertOpen($entry->session);

        $declaration = $entry->declaration;

        $entry->forceFill([
            'actual_in_ngwee' => 0,
            'received_at' => null,
            'penalty_days' => 0,
            'variance_ngwee' => -$entry->getRawOriginal('expected_in_ngwee'),
            'savings_portion_ngwee' => $declaration?->getRawOriginal('saving_amount_ngwee') ?? 0,
            'repayment_portion_ngwee' => $declaration?->getRawOriginal('loan_repayment_amount_ngwee') ?? 0,
        ])->save();

        return $entry->refresh();
    }

    /**
     * Pays out the loan this member is queued for and records it on the sheet.
     *
     * The money physically leaves the table here, so it is posted to the loan ledger
     * here too, through the same queue that governs the order. Concluding the session
     * afterwards does not repeat it.
     */
    public function confirmDisbursement(
        TradingEntry $entry,
        Member $actor,
        ?CarbonInterface $at = null,
        ?string $outOfOrderReason = null,
    ): TradingEntry {
        $session = $entry->session;
        $this->assertOpen($session);

        $month = $session->cycleMonth;
        $loan = $this->queuedLoanFor($entry->member_id, $month);

        if ($loan === null) {
            throw new DomainRuleException(
                "{$entry->member->full_name} has no approved loan waiting in the {$month->label()} queue."
            );
        }

        $this->queue->disburse($loan, $month, $actor, $outOfOrderReason);

        $entry->forceFill([
            'actual_out_ngwee' => Kwacha::toNgwee($loan->principal_ngwee),
            'disbursed_at' => $at ?? Carbon::now(),
        ])->save();

        return $entry->refresh();
    }

    /**
     * The day's running position, which the console's sticky footer reads.
     *
     * @return array<string, int>
     */
    public function totals(TradingSession $session): array
    {
        $entries = $session->entries()->get();

        $expectedIn = $entries->sum(fn (TradingEntry $e): int => $e->getRawOriginal('expected_in_ngwee'));
        $actualIn = $entries->sum(fn (TradingEntry $e): int => $e->getRawOriginal('actual_in_ngwee'));
        $expectedOut = $entries->sum(fn (TradingEntry $e): int => $e->getRawOriginal('expected_out_ngwee'));
        $actualOut = $entries->sum(fn (TradingEntry $e): int => $e->getRawOriginal('actual_out_ngwee'));

        return [
            'expected_in_ngwee' => $expectedIn,
            'actual_in_ngwee' => $actualIn,
            'expected_out_ngwee' => $expectedOut,
            'actual_out_ngwee' => $actualOut,
            'variance_ngwee' => $actualIn - $expectedIn,
            /* What is physically on the table right now. */
            'cash_position_ngwee' => $actualIn - $actualOut,
            'projected_position_ngwee' => $expectedIn - $expectedOut,
            'received_count' => $entries->filter(fn (TradingEntry $e): bool => $e->isReceived())->count(),
            'outstanding_count' => $entries
                ->filter(fn (TradingEntry $e): bool => ! $e->isReceived() && $e->getRawOriginal('expected_in_ngwee') > 0)
                ->count(),
            'entry_count' => $entries->count(),
        ];
    }

    /**
     * Days between the session's scheduled conclusion and the money arriving.
     *
     * The date is the one copied onto the session when it opened — already adjusted for
     * a weekend — so a payment on the Monday after a Saturday 7th is not late.
     */
    public function penaltyDays(TradingSession $session, CarbonInterface $receivedAt): int
    {
        return max(0, (int) $session->scheduled_conclude_date->copy()->startOfDay()
            ->diffInDays($receivedAt->copy()->startOfDay(), false));
    }

    /**
     * Members owed money on the day, keyed by member id.
     *
     * @return Collection<int, int>
     */
    protected function queuedDisbursements(CycleMonth $month): Collection
    {
        return $this->queue->pending($month)
            ->groupBy('member_id')
            ->map(fn (Collection $loans): int => (int) $loans
                ->sum(fn (Loan $loan): int => Kwacha::toNgwee($loan->principal_ngwee)));
    }

    protected function queuedLoanFor(int $memberId, CycleMonth $month): ?Loan
    {
        return $this->queue->pending($month)->firstWhere('member_id', $memberId);
    }

    protected function assertOpen(TradingSession $session): void
    {
        if (! $session->isOpen()) {
            throw new TradingSessionClosedException(
                'The '.$session->cycleMonth->label().' trading session has been concluded and can no longer be changed.'
            );
        }
    }
}
