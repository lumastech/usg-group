<?php

namespace App\Http\Controllers\App;

use App\Domain\Savings\MemberBalanceCalculator;
use App\Domain\Savings\SavingsLedger;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Savings\StoreSavingsDepositRequest;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;

/**
 * Records one member's savings for a month.
 *
 * Every rule about the amount belongs to SavingsLedger; this turns its refusal into a
 * validation error on the amount field so the modal shows it where the treasurer is
 * looking, and refreshes the member's snapshot so the matrix agrees immediately.
 */
class SavingsDepositController extends Controller
{
    public function __invoke(
        StoreSavingsDepositRequest $request,
        SavingsLedger $ledger,
        MemberBalanceCalculator $balances,
    ): RedirectResponse {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors([
                'amount_ngwee' => 'Your login is not linked to a member record, so it cannot be recorded as the actor. Ask an administrator to link it.',
            ]);
        }

        $member = $request->member();
        $month = $request->month();
        $amount = Kwacha::ofNgwee($request->integer('amount_ngwee'));

        try {
            $ledger->record(
                $member,
                $month,
                $amount,
                $actor,
                declared: $request->filled('declared_amount_ngwee')
                    ? Kwacha::ofNgwee($request->integer('declared_amount_ngwee'))
                    : null,
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['amount_ngwee' => $exception->getMessage()]);
        }

        $balances->rebuildFor($member, $month);

        return back()->with(
            'success',
            Kwacha::format($amount)." recorded for {$member->full_name} ({$month->label()}).",
        );
    }
}
