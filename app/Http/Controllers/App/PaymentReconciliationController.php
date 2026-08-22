<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Payments\Reconciler;
use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\PaymentIntent;
use App\Models\PaymentReconciliation;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "We checked, and it agreed" — which is the answer the group needs at share-out, not
 * the absence of an alarm.
 */
class PaymentReconciliationController extends Controller
{
    public function __construct(
        protected Reconciler $reconciler,
        protected CurrentCycle $currentCycle,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', PaymentIntent::class);

        $runs = PaymentReconciliation::query()
            ->acrossCycles()
            ->orderByDesc('for_date')
            ->limit(30)
            ->get()
            ->map(fn (PaymentReconciliation $run): array => [
                'id' => $run->id,
                'for_date' => $run->for_date->toDateString(),
                'collections_count' => $run->collections_count,
                'collections_ngwee' => Kwacha::toNgwee($run->collections_ngwee),
                'transfers_count' => $run->transfers_count,
                'transfers_ngwee' => Kwacha::toNgwee($run->transfers_ngwee),
                'fees_ngwee' => Kwacha::toNgwee($run->fees_ngwee),
                'provider_balance_ngwee' => $run->provider_balance_ngwee === null
                    ? null
                    : Kwacha::toNgwee($run->provider_balance_ngwee),
                'unmatched' => $run->unmatched ?? [],
                'unmatched_count' => $run->unmatched_count,
                'agrees' => $run->agrees(),
                'ran_at' => $run->ran_at->toIso8601String(),
            ])
            ->all();

        return Inertia::render('app/payments/Reconciliation', [
            'runs' => $runs,
            'can_run' => $this->currentCycle->get() !== null
                && request()->user()->can('reconcile', PaymentIntent::class),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('reconcile', PaymentIntent::class);

        $cycle = $this->currentCycle->get();

        if ($cycle === null) {
            return back()->with('error', 'There is no active cycle to reconcile against.');
        }

        $days = max(0, min(31, $request->integer('days', 1)));
        $to = Carbon::today();

        try {
            $run = $this->reconciler->run($cycle, $to->copy()->subDays($days), $to, $request->user()->member);
        } catch (PaymentGatewayException $exception) {
            return back()->with('error', $exception->reason());
        }

        return back()->with('success', $run->agrees()
            ? 'Both sides agree; nothing outstanding.'
            : "{$run->unmatched_count} item(s) need a look.");
    }
}
