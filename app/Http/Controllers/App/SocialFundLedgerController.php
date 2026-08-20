<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Reporting\SocialFundOverview;
use App\Enums\SocialFundTransactionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\SocialFundTransactionResource;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every line of the fund's ledger, filterable and exportable.
 *
 * Sorting is restricted to a fixed list of columns so a query string cannot order by
 * anything the screen does not offer.
 */
class SocialFundLedgerController extends Controller
{
    protected const SORTABLE = ['occurred_on', 'amount_ngwee', 'type'];

    public function __construct(protected SocialFundOverview $overview) {}

    public function __invoke(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', SocialFundTransaction::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/fund/Ledger', [
                'entries' => ['data' => [], 'meta' => null],
                'overview' => null,
                'types' => $this->typeOptions(),
                'members' => [],
                'filters' => ['type' => null, 'member_id' => null, 'search' => null],
                'sort' => ['column' => 'occurred_on', 'direction' => 'desc'],
            ]);
        }

        $sort = in_array($request->string('sort')->toString(), self::SORTABLE, true)
            ? $request->string('sort')->toString()
            : 'occurred_on';

        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';

        $type = SocialFundTransactionType::tryFrom($request->string('type')->toString())?->value;

        $entries = SocialFundTransaction::query()
            ->forCycle($cycle)
            ->with('member', 'cycleMonth', 'recordedBy', 'secondApprover')
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->when($request->filled('member_id'), fn ($query) => $query->where('member_id', $request->integer('member_id')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($inner) => $inner
                    ->where('note', 'like', $term)
                    ->orWhereHas('member', fn ($member) => $member->where('full_name', 'like', $term)));
            })
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('app/fund/Ledger', [
            'entries' => SocialFundTransactionResource::collection($entries),
            'overview' => $this->overview->for($cycle),
            'types' => $this->typeOptions(),
            'members' => $cycle->members()->get()->map(fn (Member $member): array => [
                'value' => $member->id,
                'label' => "{$member->member_number}. {$member->full_name}",
            ])->all(),
            'filters' => [
                'type' => $type,
                'member_id' => $request->integer('member_id') ?: null,
                'search' => $request->string('search')->toString() ?: null,
            ],
            'sort' => ['column' => $sort, 'direction' => $direction],
        ]);
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    protected function typeOptions(): array
    {
        return array_map(
            fn (SocialFundTransactionType $type): array => ['value' => $type->value, 'label' => $type->label()],
            SocialFundTransactionType::cases(),
        );
    }
}
