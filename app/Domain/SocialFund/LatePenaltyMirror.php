<?php

namespace App\Domain\SocialFund;

use App\Enums\LoanTransactionType;
use App\Enums\SocialFundTransactionType;
use App\Models\LoanTransaction;
use App\Models\SocialFundTransaction;
use App\Support\Kwacha;
use Brick\Money\Money;
use Illuminate\Support\Collection;

/**
 * The daily late-transfer penalty belongs to the Social Fund, not to the lending pool.
 *
 * A member who pays late is charged K100 a day on the loan ledger; the same amount is
 * mirrored into the fund as a LatePenaltyInflow carrying a back-reference to the loan
 * entry it came from. Two ledgers hold the figure, and the back-reference is what lets
 * unity:reconcile-social-fund prove they still agree.
 *
 * Only the daily late penalty is mirrored. The 10% missed-installment penalty is a
 * charge on the loan itself and stays with the lending pool, which is why the loan side
 * of the reconciliation counts LatePenaltyDaily alone.
 */
class LatePenaltyMirror
{
    public function __construct(protected SocialFundLedger $ledger) {}

    /** Mirrors one loan penalty into the fund, or returns the mirror already posted. */
    public function mirror(LoanTransaction $penalty): ?SocialFundTransaction
    {
        if ($penalty->type !== LoanTransactionType::LatePenaltyDaily) {
            return null;
        }

        $existing = $this->mirrorOf($penalty);

        if ($existing !== null) {
            return $existing;
        }

        $loan = $penalty->loan()->with('member', 'cycle')->first();

        if ($loan === null) {
            return null;
        }

        return $this->ledger->receive(
            $loan->cycle,
            SocialFundTransactionType::LatePenaltyInflow,
            $penalty->amount_ngwee,
            $penalty->occurred_on,
            $loan->member,
            null,
            $penalty,
            'Late transfer penalty on loan #'.$loan->id.($penalty->note === null ? '' : ' — '.$penalty->note),
        );
    }

    /** The fund entry raised from a given loan penalty, if it has been mirrored. */
    public function mirrorOf(LoanTransaction $penalty): ?SocialFundTransaction
    {
        return SocialFundTransaction::query()
            ->acrossCycles()
            ->where('type', SocialFundTransactionType::LatePenaltyInflow->value)
            ->where('reference_type', $penalty->getMorphClass())
            ->where('reference_id', $penalty->getKey())
            ->first();
    }

    /** Total charged on the loan side, as a positive figure. */
    public function chargedOnLoans(int $cycleId): Money
    {
        return Kwacha::ofNgwee((int) LoanTransaction::query()
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->where('loans.cycle_id', $cycleId)
            ->where('loan_transactions.type', LoanTransactionType::LatePenaltyDaily->value)
            ->sum('loan_transactions.amount_ngwee'));
    }

    /**
     * Loan penalties in the cycle that never reached the fund.
     *
     * @return Collection<int, LoanTransaction>
     */
    public function unmirrored(int $cycleId): Collection
    {
        return LoanTransaction::query()
            ->join('loans', 'loans.id', '=', 'loan_transactions.loan_id')
            ->select('loan_transactions.*')
            ->where('loans.cycle_id', $cycleId)
            ->where('loan_transactions.type', LoanTransactionType::LatePenaltyDaily->value)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('social_fund_transactions')
                    ->whereColumn('social_fund_transactions.reference_id', 'loan_transactions.id')
                    ->where('social_fund_transactions.reference_type', (new LoanTransaction)->getMorphClass())
                    ->where('social_fund_transactions.type', SocialFundTransactionType::LatePenaltyInflow->value);
            })
            ->get();
    }
}
