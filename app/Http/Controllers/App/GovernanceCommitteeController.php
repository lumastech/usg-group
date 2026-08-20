<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Governance\CommitteeTermService;
use App\Domain\Governance\SuccessionPlanner;
use App\Enums\CommitteeRole;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Governance\EndCommitteeTermRequest;
use App\Http\Requests\Governance\StoreCommitteeTermRequest;
use App\Http\Resources\CommitteeTermResource;
use App\Models\CommitteeTerm;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The committee register: who is serving, who has served, and who would come next.
 *
 * The succession proposal on this page appoints nobody. It reads the constitution's
 * order out loud — the deputies step up — and every line still has to be confirmed by
 * the group before anything is written.
 */
class GovernanceCommitteeController extends Controller
{
    public function __construct(
        protected CommitteeTermService $terms,
        protected SuccessionPlanner $succession,
    ) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', CommitteeTerm::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/governance/Committee', [
                'cycle' => null,
                'current' => [],
                'history' => [],
                'succession' => [],
                'roles' => $this->roleOptions(),
                'members' => [],
                'abilities' => ['record' => false],
            ]);
        }

        $history = CommitteeTerm::query()
            ->forCycle($cycle->id)
            ->whereNotNull('ended_at')
            ->with('member')
            ->orderByDesc('ended_at')
            ->get();

        return Inertia::render('app/governance/Committee', [
            'cycle' => ['id' => $cycle->id, 'name' => $cycle->name],
            'current' => CommitteeTermResource::collection($this->terms->current($cycle)),
            'history' => CommitteeTermResource::collection($history),
            'succession' => $this->succession->proposeFor($cycle),
            'roles' => $this->roleOptions(),
            'members' => Member::query()
                ->forCycle($cycle->id)
                ->active()
                ->orderBy('member_number')
                ->get()
                ->map(fn (Member $member): array => [
                    'id' => $member->id,
                    'member_number' => $member->member_number,
                    'full_name' => $member->full_name,
                ])->all(),
            'abilities' => [
                'record' => $request->user()->can('create', CommitteeTerm::class),
            ],
        ]);
    }

    public function store(StoreCommitteeTermRequest $request, CurrentCycle $currentCycle): RedirectResponse
    {
        $cycle = $currentCycle->getOrFail();
        $member = Member::query()->forCycle($cycle->id)->findOrFail($request->integer('member_id'));

        return $this->attempt(function () use ($request, $member, $cycle): string {
            $term = $this->terms->appoint($member, $request->role(), $cycle, $request->startedAt());

            return "{$member->full_name} is recorded as {$term->role->label()}.";
        });
    }

    /**
     * Ends a term. A resignation goes through the notice arithmetic; anything else is
     * simply the year running out.
     */
    public function destroy(EndCommitteeTermRequest $request, CommitteeTerm $term): RedirectResponse
    {
        $reason = $request->reason();
        $notice = $request->noticeDate();

        return $this->attempt(function () use ($request, $term, $reason, $notice): string {
            if ($notice !== null) {
                $this->terms->resign(
                    $term,
                    $notice,
                    $request->endedAt(),
                    $request->string('notice_waiver_note')->toString() ?: null,
                );
            } else {
                $this->terms->end($term, $reason, $request->endedAt());
            }

            return "{$term->member->full_name}'s term as {$term->role->label()} has ended.";
        });
    }

    /**
     * @return array<int, array{value: string, label: string, portal_role: string|null}>
     */
    protected function roleOptions(): array
    {
        return array_map(fn (CommitteeRole $role): array => [
            'value' => $role->value,
            'label' => $role->label(),
            'portal_role' => $role->portalRole()?->value,
        ], CommitteeRole::cases());
    }

    /** @param  callable(): string  $operation */
    protected function attempt(callable $operation): RedirectResponse
    {
        try {
            $message = $operation();
        } catch (DomainRuleException $exception) {
            return back()->withErrors(['term' => $exception->getMessage()]);
        }

        return back()->with('success', $message);
    }
}
