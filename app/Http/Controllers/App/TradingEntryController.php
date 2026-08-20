<?php

namespace App\Http\Controllers\App;

use App\Domain\Trading\TradingSessionService;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Trading\ConfirmDisbursementRequest;
use App\Http\Requests\Trading\StoreTradingReceiptRequest;
use App\Models\TradingEntry;
use App\Models\TradingSession;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Marking one member's line at the trading table.
 *
 * Nothing here posts to a ledger — the sheet is a worksheet until the session is
 * concluded — with the single exception of confirming a disbursement, because that is
 * money physically leaving the table and the loan ledger must know it the moment it
 * does.
 */
class TradingEntryController extends Controller
{
    public function __construct(protected TradingSessionService $sessions) {}

    /** Money received: the amount and, importantly, when it actually arrived. */
    public function store(StoreTradingReceiptRequest $request, TradingEntry $entry): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['actual_in_ngwee' => 'Your login is not linked to a member record.']);
        }

        try {
            $updated = $this->sessions->markReceived(
                $entry,
                Kwacha::ofNgwee($request->integer('actual_in_ngwee')),
                $request->receivedAt(),
                $actor,
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['actual_in_ngwee' => $exception->getMessage()]);
        }

        return back()->with('success', $this->receiptMessage($updated));
    }

    /** Undoes a receipt marked in error, while the session is still open. */
    public function destroy(Request $request, TradingEntry $entry): RedirectResponse
    {
        $this->authorize('operate', TradingSession::class);

        try {
            $this->sessions->clearReceipt($entry);
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['entry' => $exception->getMessage()]);
        }

        return back()->with('success', "Cleared the receipt for {$entry->member->full_name}.");
    }

    /** Pays out the loan this member is queued for, in the queue's order. */
    public function disburse(ConfirmDisbursementRequest $request, TradingEntry $entry): RedirectResponse
    {
        $actor = $request->user()->member;

        if ($actor === null) {
            return back()->withErrors(['out_of_order_reason' => 'Your login is not linked to a member record.']);
        }

        try {
            $updated = $this->sessions->confirmDisbursement(
                $entry,
                $actor,
                outOfOrderReason: $request->string('out_of_order_reason')->toString() ?: null,
            );
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['out_of_order_reason' => $exception->getMessage()]);
        }

        return back()->with(
            'success',
            Kwacha::format($updated->actual_out_ngwee)." disbursed to {$entry->member->full_name}.",
        );
    }

    protected function receiptMessage(TradingEntry $entry): string
    {
        $message = Kwacha::format($entry->actual_in_ngwee)." received from {$entry->member->full_name}.";

        if ($entry->penalty_days > 0) {
            $message .= ' '.$entry->penalty_days.' day'.($entry->penalty_days === 1 ? '' : 's')
                .' late — the penalty is charged when the session is concluded.';
        }

        return $message;
    }
}
