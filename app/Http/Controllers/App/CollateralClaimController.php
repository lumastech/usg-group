<?php

namespace App\Http\Controllers\App;

use App\Domain\Loans\DefaultWorkflowService;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\SignOffCollateralClaimRequest;
use App\Http\Requests\Loans\StoreCollateralClaimRequest;
use App\Models\CollateralClaim;
use App\Models\Loan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The guided claim against a defaulting member's household goods.
 *
 * Drafted item by item, signed by a second committee member on the same device, and
 * only then enforceable. Each step refuses to run out of order, so the sequence the
 * constitution describes cannot be short-circuited from the screen.
 */
class CollateralClaimController extends Controller
{
    public function __construct(protected DefaultWorkflowService $workflow) {}

    public function store(StoreCollateralClaimRequest $request, Loan $loan): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['items' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->workflow->openClaim($loan->load('member'), $request->items(), $actor->load('user'));
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['items' => $exception->getMessage()]);
        }

        return back()->with('success', 'The collateral claim is drafted and awaiting a second signature.');
    }

    public function signOff(SignOffCollateralClaimRequest $request, CollateralClaim $claim): RedirectResponse
    {
        try {
            $this->workflow->signOff($claim->load('loan.member', 'preparedBy.user'), $request->signer());
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['approver_email' => $exception->getMessage()]);
        }

        return back()->with('success', 'The claim now carries two committee signatures and may be enforced.');
    }

    public function enforce(Request $request, CollateralClaim $claim): RedirectResponse
    {
        $this->authorize('enforce', $claim);

        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['claim' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->workflow->enforce($claim, $actor);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['claim' => $exception->getMessage()]);
        }

        return back()->with('success', 'The claim has been enforced.');
    }

    public function release(Request $request, CollateralClaim $claim): RedirectResponse
    {
        $this->authorize('release', $claim);

        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['claim' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->workflow->release($claim, $actor, $request->string('note')->toString() ?: null);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['claim' => $exception->getMessage()]);
        }

        return back()->with('success', 'The claim has been released.');
    }
}
