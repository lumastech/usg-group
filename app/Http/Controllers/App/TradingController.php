<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Declarations\DeclarationWindow;
use App\Domain\Trading\TradingConcluder;
use App\Domain\Trading\TradingSessionService;
use App\Http\Controllers\Controller;
use App\Http\Resources\TradingEntryResource;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\TradingSession;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The trading console: the operational heart of the month.
 *
 * The session is opened lazily on first view once the declaration window has closed,
 * so the treasurer arriving at the table on the 4th finds the sheet already laid out
 * rather than having to remember to create it. Opening is idempotent, so a late
 * declaration captured afterwards simply appears on the next load.
 */
class TradingController extends Controller
{
    public function __construct(
        protected TradingSessionService $sessions,
        protected TradingConcluder $concluder,
        protected DeclarationWindow $window,
        protected DeclarationService $declarations,
    ) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', TradingSession::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return $this->empty();
        }

        $month = $this->resolveMonth($request, $cycle);

        if ($month === null) {
            return $this->empty();
        }

        $session = $this->sessionFor($month, $request);

        return Inertia::render('app/trading/Index', [
            'cycle' => ['id' => $cycle->id, 'name' => $cycle->name],
            'month' => $this->window->payload($month),
            'months' => $cycle->months->map(fn (CycleMonth $row): array => [
                'id' => $row->id,
                'sequence' => $row->sequence,
                'label' => $row->label(),
                'status' => $row->status,
            ])->all(),
            'session' => $session === null ? null : [
                'id' => $session->id,
                'status' => $session->status,
                'status_label' => $session->status->label(),
                'scheduled_conclude_date' => $session->scheduled_conclude_date->toDateString(),
                'concluded_at' => $session->concluded_at?->toIso8601String(),
                'concluded_by' => $session->concludedBy?->full_name,
            ],
            'entries' => $session === null ? [] : TradingEntryResource::collection(
                $session->entries()->with('member', 'declaration')->get()
                    ->sortBy(fn ($entry): int => $entry->member->member_number)
                    ->values(),
            ),
            'totals' => $session === null ? null : $this->sessions->totals($session),
            'preview' => $session === null || ! $session->isOpen()
                ? null
                : $this->concluder->preview($session),
            'missing' => $this->declarations->missingFor($month)
                ->map(fn (Member $member): array => [
                    'id' => $member->id,
                    'member_number' => $member->member_number,
                    'full_name' => $member->full_name,
                ])->all(),
            'filters' => ['month' => $month->sequence],
            'abilities' => [
                'operate' => $request->user()->can('operate', TradingSession::class),
                'conclude' => $session !== null && $request->user()->can('conclude', $session),
            ],
        ]);
    }

    /**
     * The month's session, opened on the spot once declarations have closed.
     *
     * Before the window shuts there is deliberately nothing: opening early would lock
     * declarations members are still entitled to change.
     */
    protected function sessionFor(CycleMonth $month, Request $request): ?TradingSession
    {
        $existing = $this->sessions->find($month);

        if ($existing !== null) {
            if ($existing->isOpen() && $request->user()->can('operate', TradingSession::class)) {
                $this->sessions->syncEntries($existing);
            }

            return $existing->refresh();
        }

        if ($this->window->isOpen($month) || $this->window->isBeforeOpen($month)) {
            return null;
        }

        return $request->user()->can('operate', TradingSession::class)
            ? $this->sessions->openFor($month)
            : null;
    }

    protected function resolveMonth(Request $request, Cycle $cycle): ?CycleMonth
    {
        $sequence = $request->integer('month') ?: null;

        return $sequence === null ? $cycle->monthFor(now()) : $cycle->monthAt($sequence);
    }

    protected function empty(): Response
    {
        return Inertia::render('app/trading/Index', [
            'cycle' => null,
            'month' => null,
            'months' => [],
            'session' => null,
            'entries' => [],
            'totals' => null,
            'preview' => null,
            'missing' => [],
            'filters' => ['month' => null],
            'abilities' => ['operate' => false, 'conclude' => false],
        ]);
    }
}
