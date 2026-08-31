<?php

namespace App\Console\Commands;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Wallets\WalletReconciler;
use App\Models\Cycle;
use App\Models\PaymentReconciliation;
use App\Support\Kwacha;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The daily check that every wallet balance is backed by money the group actually holds.
 *
 * A mismatch is an alarm, not a report. It exits non-zero so whatever runs it — cron,
 * the scheduler's failure handler, a monitor — treats it as the incident it is. A
 * wallet credited with no money behind it needs no ledger tampering at all, and this
 * is the only place it shows up.
 */
class ReconcileWallets extends Command
{
    protected $signature = 'unity:reconcile-wallets
        {--cash= : What the treasurer counted in the tin, in Kwacha, if it has been counted}
        {--cycle= : Cycle id to run against, defaulting to the current cycle}';

    protected $description = 'Check that the wallet float is backed by the provider balance and the cash tin';

    public function handle(CurrentCycle $currentCycle, WalletReconciler $reconciler): int
    {
        $cycle = $this->option('cycle') !== null
            ? Cycle::find($this->option('cycle'))
            : $currentCycle->get();

        if (! $cycle instanceof Cycle) {
            $this->components->error('No cycle to reconcile. Pass --cycle or activate one.');

            return self::FAILURE;
        }

        $counted = $this->option('cash') === null ? null : (int) round((float) $this->option('cash') * 100);

        $result = $reconciler->check($cycle, $counted);

        $this->report($result);
        $this->record($cycle, $result);

        if ($result['provider_unreachable']) {
            $this->components->error('The provider could not be reached, so the float was not checked.');

            return self::FAILURE;
        }

        if (! $result['balances']) {
            $this->components->error(sprintf(
                'ALARM: the wallet float is out by %s. Somebody has to look at this today.',
                Kwacha::format((int) $result['wallet_variance_ngwee']),
            ));

            return self::FAILURE;
        }

        $this->components->info('The wallet float agrees with the money the group holds.');

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $result */
    protected function report(array $result): void
    {
        $this->components->twoColumnDetail('Wallets hold', Kwacha::format((int) $result['wallet_total_ngwee']));
        $this->components->twoColumnDetail(
            '  owed to members',
            Kwacha::format((int) $result['member_liability_ngwee']),
        );
        $this->components->twoColumnDetail('  the group\'s', Kwacha::format((int) $result['group_wallet_ngwee']));
        $this->components->twoColumnDetail(
            'Provider balance',
            $result['provider_balance_ngwee'] === null
                ? 'unavailable'
                : Kwacha::format((int) $result['provider_balance_ngwee']),
        );
        $this->components->twoColumnDetail(
            'Cash tin'.($result['cash_tin_counted'] ? ' (counted)' : ' (from the books)'),
            Kwacha::format((int) $result['cash_tin_ngwee']),
        );
        $this->components->twoColumnDetail(
            'Withdrawals in flight',
            Kwacha::format((int) $result['withdrawals_in_flight_ngwee']),
        );

        /* Reported, never alarmed on: see WalletReconciler::expectedGroupBalanceNgwee(). */
        $this->components->twoColumnDetail(
            'Group wallet against the ledgers',
            Kwacha::format((int) $result['group_wallet_variance_ngwee']),
        );
    }

    /**
     * Kept beside the day's payment reconciliation, which is where it will be read.
     *
     * @param  array<string, mixed>  $result
     */
    protected function record(Cycle $cycle, array $result): void
    {
        PaymentReconciliation::query()->updateOrCreate(
            ['for_date' => Carbon::today()],
            [
                'cycle_id' => $cycle->id,
                'wallet_variance_ngwee' => $result['wallet_variance_ngwee'],
                'group_wallet_variance_ngwee' => $result['group_wallet_variance_ngwee'],
                'wallet_invariants' => $result,
                'ran_at' => Carbon::now(),
            ],
        );
    }
}
