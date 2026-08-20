<?php

namespace App\Http\Controllers\App;

use App\Domain\Trading\TradingConcluder;
use App\Domain\Trading\TradingSessionService;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\CycleMonth;
use App\Models\TradingSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Opening and concluding a month's trading session.
 *
 * Concluding is the single act that posts the month. It is deliberately a separate,
 * confirmed step rather than something that happens as each row is marked: the
 * treasurer works the sheet all day and commits it once, against a preview of exactly
 * what will be posted.
 */
class TradingSessionController extends Controller
{
    public function __construct(
        protected TradingSessionService $sessions,
        protected TradingConcluder $concluder,
    ) {}

    /** Opens the session by hand, for a month whose window has already closed. */
    public function store(Request $request, CycleMonth $month): RedirectResponse
    {
        $this->authorize('operate', TradingSession::class);

        try {
            $this->sessions->openFor($month);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['session' => $exception->getMessage()]);
        }

        return back()->with('success', "The {$month->label()} trading session is open.");
    }

    /**
     * Posts the month.
     *
     * A refusal anywhere inside — a member who paid towards a loan they do not hold, a
     * savings amount the ledger will not take — rolls the whole conclusion back, so the
     * treasurer fixes the one row and concludes again rather than reconciling a
     * half-posted month.
     */
    public function conclude(Request $request, TradingSession $session): RedirectResponse
    {
        $this->authorize('conclude', $session);

        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['session' => 'Your login is not linked to a member record.']);
        }

        try {
            $this->concluder->conclude($session, $actor);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['session' => $exception->getMessage()]);
        }

        return back()->with(
            'success',
            "The {$session->cycleMonth->label()} trading session is concluded and the month is posted.",
        );
    }
}
