<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\SocialFund\LatePenaltyMirror;
use App\Domain\SocialFund\SocialFundLedger;
use App\Enums\SocialFundTransactionType;
use App\Models\Cycle;
use App\Models\LoanTransaction;
use App\Support\Kwacha;
use Illuminate\Console\Command;

/**
 * Proves the two ledgers still agree about late-transfer penalties.
 *
 * Every K100-a-day penalty charged on a loan is mirrored into the Social Fund by a
 * listener. Two ledgers holding one figure can drift — an event lost mid-transaction, a
 * penalty imported straight into the loan ledger — so this command sums both sides and
 * names the entries that never crossed over. With --fix it posts the missing mirrors.
 */
class ReconcileSocialFund extends Command
{
    protected $signature = 'unity:reconcile-social-fund
        {--cycle= : Cycle id to reconcile, defaulting to the current cycle}
        {--fix : Post the missing mirror entries rather than only reporting them}';

    protected $description = 'Assert the loan-side late penalties and the social fund inflows agree';

    public function handle(
        CurrentCycle $currentCycle,
        LatePenaltyMirror $mirror,
        SocialFundLedger $ledger,
    ): int {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find($this->option('cycle'))
            : $currentCycle->get();

        if (! $cycle instanceof Cycle) {
            $this->components->error('No cycle to reconcile. Pass --cycle or activate one.');

            return self::FAILURE;
        }

        $currentCycle->set($cycle);

        $missing = $mirror->unmirrored($cycle->id);

        if ($this->option('fix') && $missing->isNotEmpty()) {
            $this->components->info("Mirroring {$missing->count()} penalty entr(ies) into the fund.");

            $missing->each(fn (LoanTransaction $penalty) => $mirror->mirror($penalty));

            $missing = $mirror->unmirrored($cycle->id);
        }

        $charged = $mirror->chargedOnLoans($cycle->id);
        $received = $ledger->totalReceived($cycle, SocialFundTransactionType::LatePenaltyInflow);

        $this->components->twoColumnDetail('Cycle', $cycle->name);
        $this->components->twoColumnDetail('Late penalties charged on loans', Kwacha::format($charged));
        $this->components->twoColumnDetail('Late penalty inflows in the fund', Kwacha::format($received));
        $this->components->twoColumnDetail('Fund balance', Kwacha::format($ledger->balance($cycle)));

        if ($missing->isNotEmpty()) {
            $this->newLine();
            $this->components->error("{$missing->count()} loan penalt(ies) have no matching fund inflow.");

            $this->table(
                ['Loan entry', 'Loan', 'Charged on', 'Amount'],
                $missing->map(fn (LoanTransaction $penalty): array => [
                    $penalty->id,
                    $penalty->loan_id,
                    $penalty->occurred_on->toDateString(),
                    Kwacha::format($penalty->amount_ngwee),
                ])->all(),
            );

            $this->components->warn('Run again with --fix to post the missing mirrors.');

            return self::FAILURE;
        }

        if (! $charged->isEqualTo($received)) {
            $this->newLine();
            $this->components->error(
                'Every penalty is mirrored, but the totals differ by '
                .Kwacha::format(Kwacha::toNgwee($received) - Kwacha::toNgwee($charged))
                .'. The fund holds an inflow the loan ledger does not account for.'
            );

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('The loan ledger and the social fund agree.');

        return self::SUCCESS;
    }
}
