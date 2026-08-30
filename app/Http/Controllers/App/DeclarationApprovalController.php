<?php

namespace App\Http\Controllers\App;

use App\Domain\Declarations\DeclarationService;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\Declaration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The committee asking a member for what they declared.
 *
 * A declaration arrives as a request. Approving it accepts the figures: the member can
 * no longer edit them, and from that point either the member or the treasury may start
 * the payment. Reopening hands it back, and is only possible before the month locks.
 */
class DeclarationApprovalController extends Controller
{
    public function __construct(protected DeclarationService $declarations) {}

    public function store(Request $request, Declaration $declaration): RedirectResponse
    {
        $this->authorize('approve', $declaration);

        try {
            $this->declarations->approve($declaration, $request->user()->actingMember());
        } catch (DomainRuleException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            "{$declaration->member->full_name}'s declaration is approved and waiting for payment.",
        );
    }

    public function destroy(Request $request, Declaration $declaration): RedirectResponse
    {
        $this->authorize('reopen', $declaration);

        try {
            $this->declarations->reopen($declaration);
        } catch (DomainRuleException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            "{$declaration->member->full_name}'s declaration has been reopened for editing.",
        );
    }
}
