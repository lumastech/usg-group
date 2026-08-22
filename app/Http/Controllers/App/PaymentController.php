<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Enums\PaymentDirection;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentIntentResource;
use App\Models\Member;
use App\Models\PaymentIntent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every payment the group has asked for or sent, and the short list of those that
 * need somebody to decide something.
 *
 * Sorting is restricted to a fixed list of columns so a query string cannot order by
 * anything the screen does not offer.
 */
class PaymentController extends Controller
{
    protected const SORTABLE = ['created_at', 'amount_ngwee', 'status', 'purpose'];

    public function __invoke(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', PaymentIntent::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/payments/Index', [
                'payments' => ['data' => [], 'meta' => null],
                'summary' => $this->emptySummary(),
                'options' => $this->options(),
                'members' => [],
                'filters' => $this->filters($request),
                'sort' => ['column' => 'created_at', 'direction' => 'desc'],
            ]);
        }

        $sort = in_array($request->string('sort')->toString(), self::SORTABLE, true)
            ? $request->string('sort')->toString()
            : 'created_at';

        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';

        $payments = $this->query($request, $cycle->id)
            ->with('member', 'destination', 'requestedBy')
            ->orderBy($sort, $direction)
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('app/payments/Index', [
            'payments' => PaymentIntentResource::collection($payments),
            'summary' => $this->summary($cycle->id),
            'options' => $this->options(),
            'members' => $cycle->members()->get()->map(fn (Member $member): array => [
                'value' => $member->id,
                'label' => "{$member->member_number}. {$member->full_name}",
            ])->all(),
            'filters' => $this->filters($request),
            'sort' => ['column' => $sort, 'direction' => $direction],
        ]);
    }

    /** @return Builder<PaymentIntent> */
    protected function query(Request $request, int $cycleId)
    {
        return PaymentIntent::query()
            ->forCycle($cycleId)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('direction'), fn ($query) => $query->where('direction', $request->string('direction')->toString()))
            ->when($request->filled('purpose'), fn ($query) => $query->where('purpose', $request->string('purpose')->toString()))
            ->when($request->filled('member_id'), fn ($query) => $query->where('member_id', $request->integer('member_id')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.$request->string('search')->trim().'%';

                $query->where(fn ($inner) => $inner
                    ->where('reference', 'like', $term)
                    ->orWhere('provider_reference', 'like', $term)
                    ->orWhereHas('member', fn ($member) => $member->where('full_name', 'like', $term)));
            });
    }

    /**
     * The three figures the committee actually watches: money in flight, money the
     * ledgers have not taken, and money somebody has to decide about.
     *
     * @return array<string, int>
     */
    protected function summary(int $cycleId): array
    {
        $base = fn () => PaymentIntent::query()->forCycle($cycleId);

        return [
            'in_flight' => (clone $base())->awaitingOutcome()->count(),
            'unposted' => (clone $base())->unposted()->count(),
            'needs_attention' => (clone $base())->needsAttention()->count(),
            'collected_ngwee' => (int) (clone $base())->collections()
                ->where('status', PaymentStatus::Posted->value)
                ->sum('amount_ngwee'),
            'sent_ngwee' => (int) (clone $base())->transfers()
                ->where('status', PaymentStatus::Posted->value)
                ->sum('amount_ngwee'),
            'fees_ngwee' => (int) (clone $base())->sum('fee_ngwee'),
        ];
    }

    /** @return array<string, int> */
    protected function emptySummary(): array
    {
        return [
            'in_flight' => 0,
            'unposted' => 0,
            'needs_attention' => 0,
            'collected_ngwee' => 0,
            'sent_ngwee' => 0,
            'fees_ngwee' => 0,
        ];
    }

    /**
     * @return array<string, array<int, array{value: string, label: string}>>
     */
    protected function options(): array
    {
        return [
            'statuses' => array_map(
                fn (PaymentStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                PaymentStatus::cases(),
            ),
            'directions' => array_map(
                fn (PaymentDirection $direction): array => ['value' => $direction->value, 'label' => $direction->label()],
                PaymentDirection::cases(),
            ),
            'purposes' => array_map(
                fn (PaymentPurpose $purpose): array => ['value' => $purpose->value, 'label' => $purpose->label()],
                PaymentPurpose::cases(),
            ),
        ];
    }

    /** @return array<string, mixed> */
    protected function filters(Request $request): array
    {
        return [
            'status' => $request->string('status')->toString() ?: null,
            'direction' => $request->string('direction')->toString() ?: null,
            'purpose' => $request->string('purpose')->toString() ?: null,
            'member_id' => $request->integer('member_id') ?: null,
            'search' => $request->string('search')->toString() ?: null,
        ];
    }
}
