<?php

namespace App\Listeners;

use App\Domain\Reporting\MonthlyStatementPack;
use App\Events\TradingSessionConcluded;
use App\Models\Member;
use App\Models\MemberMonthBalance;
use App\Notifications\MemberStatementReady;
use App\Notifications\StatementPackCompiled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Str;

/**
 * Builds the month's pack once the session is concluded, then posts it out.
 *
 * Every member gets their own statement attached; the committee gets the four group
 * sheets. Nothing is rendered twice — the pack is built once, to the same directory
 * the treasurer's own "build pack" button writes to, and both notifications read the
 * manifest rather than re-rendering anything.
 *
 * Queued, because thirty PDFs is not work to do while a treasurer waits on a button.
 */
class SendMonthlyStatementPack implements ShouldQueue
{
    public function __construct(protected MonthlyStatementPack $pack) {}

    public function handle(TradingSessionConcluded $event): void
    {
        $month = $event->session->cycleMonth;
        $cycle = $month->cycle;
        $disk = (string) config('notifications.statement_pack.disk', 'local');

        $manifest = $this->pack->build($cycle, $month, $disk);

        $members = Member::query()->forCycle($cycle)->active()->with('user')->get();

        foreach ($members as $member) {
            $member->notify(new MemberStatementReady(
                $month,
                $this->statementFor($manifest, $member),
                $this->positionFor($member, $month->id),
                $disk,
            ));
        }

        $committee = $members->filter(fn (Member $m): bool => $m->isCommitteeMember());

        foreach ($committee as $member) {
            $member->notify(new StatementPackCompiled($month, $manifest));
        }
    }

    /**
     * The member's own file in the manifest.
     *
     * Matched on the member number the pack encodes into the filename rather than on
     * the display name, which two members in the register may share.
     *
     * @param  array{files: array<int, array{label: string, path: string, bytes: int}>}  $manifest
     * @return array{label: string, path: string, bytes: int}|null
     */
    protected function statementFor(array $manifest, Member $member): ?array
    {
        $needle = '/members/'.str_pad((string) $member->member_number, 3, '0', STR_PAD_LEFT).'-';

        return collect($manifest['files'])
            ->first(fn (array $file): bool => Str::contains($file['path'], $needle));
    }

    /**
     * The three figures the statement email leads with.
     *
     * Read from the month balance the conclusion has just rebuilt, so the email and
     * the attached PDF cannot disagree.
     *
     * @return array{savings_ngwee: int, loan_balance_ngwee: int, net_value_ngwee: int}
     */
    protected function positionFor(Member $member, int $cycleMonthId): array
    {
        $balance = MemberMonthBalance::query()
            ->where('member_id', $member->id)
            ->where('cycle_month_id', $cycleMonthId)
            ->first();

        return [
            'savings_ngwee' => (int) ($balance?->getRawOriginal('cumulative_savings_ngwee') ?? 0),
            'loan_balance_ngwee' => (int) ($balance?->getRawOriginal('loan_balance_ngwee') ?? 0),
            'net_value_ngwee' => (int) ($balance?->getRawOriginal('net_value_ngwee') ?? 0),
        ];
    }
}
