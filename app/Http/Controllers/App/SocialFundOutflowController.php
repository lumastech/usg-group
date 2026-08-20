<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\SocialFund\SocialFundLedger;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SocialFund\StoreFundOutflowRequest;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;

/**
 * A gathering expense, or a correcting adjustment, posted straight onto the ledger.
 *
 * The amount arrives signed from the form; the ledger decides from that sign whether
 * the second signature this request carries is actually required.
 */
class SocialFundOutflowController extends Controller
{
    public function __construct(protected SocialFundLedger $ledger) {}

    public function __invoke(StoreFundOutflowRequest $request, CurrentCycle $currentCycle): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['amount_ngwee' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->ledger->post(
                $currentCycle->getOrFail(),
                $request->type(),
                Kwacha::ofNgwee($request->integer('amount_ngwee')),
                $request->filled('occurred_on')
                    ? Carbon::parse($request->string('occurred_on')->toString())
                    : Carbon::today(),
                $request->subject(),
                $actor,
                $request->approver(),
                null,
                $request->input('note'),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['amount_ngwee' => $exception->getMessage()]);
        }

        return back()->with('success', 'The social fund entry has been posted.');
    }
}
