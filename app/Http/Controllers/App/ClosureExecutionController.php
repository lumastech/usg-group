<?php

namespace App\Http\Controllers\App;

use App\Domain\Payouts\PayoutExecutor;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payouts\ExecuteClosureRequest;
use App\Models\Member;
use App\Models\Payout;
use Illuminate\Http\RedirectResponse;

/**
 * Executing a closure — the last step of the wizard.
 *
 * Which of the three records comes out of it (a payout, a debt, or a next-of-kin
 * arrangement) is decided by the domain from the recomputed breakdown, not by which
 * button the committee pressed.
 */
class ClosureExecutionController extends Controller
{
    public function __construct(protected PayoutExecutor $executor) {}

    public function __invoke(ExecuteClosureRequest $request, Member $member): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['approver_email' => 'Your login is not linked to a member record.']);
        }

        try {
            $record = $this->executor->execute(
                $member->load('cycle'),
                $actor->load('user'),
                $request->approver(),
                $request->context(),
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['approver_email' => $exception->getMessage()]);
        }

        return back()->with('success', $record instanceof Payout
            ? "{$member->full_name} has been paid out and their ledgers are closed."
            : "The shortfall against {$member->full_name} has been recorded and their ledgers are closed.");
    }
}
