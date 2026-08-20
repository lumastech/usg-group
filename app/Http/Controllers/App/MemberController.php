<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Members\MembershipRegistrar;
use App\Enums\ExpulsionGround;
use App\Enums\MemberStatus;
use App\Enums\NextOfKinRelationship;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Members\StoreMemberRequest;
use App\Http\Requests\Members\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Models\User;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * The member register.
 *
 * Reading it is open to anyone who may read reports; writing to it belongs to the
 * offices holding `members.manage`, and even they cannot add a member once the
 * cycle's registration window has closed.
 */
class MemberController extends Controller
{
    /** Columns the table may be sorted by, so a query string cannot order by anything. */
    protected const SORTABLE = ['member_number', 'full_name', 'nrc_number', 'status', 'joined_on'];

    public function __construct(protected MembershipRegistrar $registrar) {}

    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Member::class);

        $sort = $this->sortColumn($request);
        $direction = $request->string('direction')->lower()->toString() === 'desc' ? 'desc' : 'asc';

        $members = Member::query()
            ->withSum('savingsTransactions', 'amount_ngwee')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(function ($query) use ($term): void {
                    $query->where('full_name', 'like', $term)
                        ->orWhere('nrc_number', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('diaspora'), fn ($query) => $query->where('is_diaspora', $request->boolean('diaspora')))
            ->orderBy($sort, $direction)
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('app/members/Index', [
            'members' => MemberResource::collection($members),
            'filters' => [
                'search' => $request->string('search')->toString() ?: null,
                'status' => $request->string('status')->toString() ?: null,
                'diaspora' => $request->has('diaspora') ? $request->boolean('diaspora') : null,
            ],
            'sort' => ['column' => $sort, 'direction' => $direction],
            'statuses' => $this->statusOptions(),
            'abilities' => [
                'create' => $request->user()->can('create', Member::class),
                'manage' => $request->user()->can(Permission::MembersManage->value),
            ],
            'registration' => $this->registrationState($currentCycle),
        ]);
    }

    public function create(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Member::class);

        return Inertia::render('app/members/Create', [
            'registration' => $this->registrationState($currentCycle),
            'relationships' => $this->relationshipOptions(),
            'canCreate' => $request->user()->can('create', Member::class),
        ]);
    }

    public function store(StoreMemberRequest $request): RedirectResponse
    {
        $member = $this->registrar->register(
            $request->cycle(),
            [
                ...$request->safe()->except('joined_on'),
                'joining_fee_ngwee' => $request->integer('joining_fee_ngwee'),
            ],
            Carbon::parse($request->date('joined_on')),
        );

        return to_route('app.members.show', $member)
            ->with('success', "{$member->full_name} is now member number {$member->member_number}.");
    }

    public function show(Request $request, Member $member): Response
    {
        $this->authorize('view', $member);

        $member->loadMissing('nextOfKin', 'user')->loadSum('savingsTransactions', 'amount_ngwee');

        return Inertia::render('app/members/Show', [
            'member' => new MemberResource($member),
            'timeline' => $this->timeline($member),
            'expulsionGrounds' => $this->expulsionGroundOptions(),
            'transitions' => $this->transitionOptions($member),
        ]);
    }

    public function edit(Request $request, Member $member, CurrentCycle $currentCycle): Response
    {
        $this->authorize('update', $member);

        return Inertia::render('app/members/Edit', [
            'member' => new MemberResource($member->loadMissing('nextOfKin', 'user')),
            'relationships' => $this->relationshipOptions(),
            'registration' => $this->registrationState($currentCycle),
        ]);
    }

    public function update(UpdateMemberRequest $request, Member $member): RedirectResponse
    {
        $member->update($request->safe()->except('next_of_kin'));

        $this->registrar->syncNextOfKin($member, $request->array('next_of_kin'));

        return to_route('app.members.show', $member)->with('success', 'Member details updated.');
    }

    /**
     * The member's audit trail, newest first, as the profile timeline renders it.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function timeline(Member $member): array
    {
        return $member->statusHistory()
            ->with('causer')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Activity $activity): array => [
                'id' => $activity->id,
                'event' => $activity->event,
                'description' => $activity->description,
                'properties' => $activity->properties,
                'causer' => $activity->causer instanceof User ? $activity->causer->name : null,
                'created_at' => $activity->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Whether the register is still open, and what a new member would pay today.
     *
     * @return array<string, mixed>
     */
    protected function registrationState(CurrentCycle $currentCycle): array
    {
        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return ['open' => false, 'closes_after_month' => null, 'month_sequence' => null];
        }

        $sequence = $this->registrar->monthSequenceFor($cycle, Carbon::today());

        return [
            'open' => $cycle->registrationOpenForMonth($sequence),
            'closes_after_month' => $cycle->registration_closes_after_month,
            'month_sequence' => $sequence,
            'cycle_starts_on' => $cycle->starts_on->toDateString(),
            'cycle_ends_on' => $cycle->ends_on->toDateString(),
            'late_registration_month' => MembershipRegistrar::LATE_REGISTRATION_MONTH,
            'standard_fee_ngwee' => Kwacha::toNgwee($cycle->joining_fee_ngwee),
            'late_fee_ngwee' => Kwacha::toNgwee($cycle->late_joining_fee_ngwee),
        ];
    }

    /** @return array<int, array<string, string>> */
    protected function statusOptions(): array
    {
        return array_map(
            fn (MemberStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            MemberStatus::cases(),
        );
    }

    /** @return array<int, array<string, string>> */
    protected function transitionOptions(Member $member): array
    {
        return array_map(
            fn (MemberStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
            $member->status->allowedTransitions(),
        );
    }

    /** @return array<int, array<string, string>> */
    protected function expulsionGroundOptions(): array
    {
        return array_map(
            fn (ExpulsionGround $ground): array => ['value' => $ground->value, 'label' => $ground->label()],
            ExpulsionGround::cases(),
        );
    }

    /** @return array<int, array<string, string>> */
    protected function relationshipOptions(): array
    {
        return array_map(
            fn (NextOfKinRelationship $relationship): array => [
                'value' => $relationship->value,
                'label' => $relationship->label(),
            ],
            NextOfKinRelationship::cases(),
        );
    }

    protected function sortColumn(Request $request): string
    {
        $sort = $request->string('sort')->toString();

        return in_array($sort, self::SORTABLE, true) ? $sort : 'member_number';
    }
}
