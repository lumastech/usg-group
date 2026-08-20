<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Payouts\ClosureRegister;
use App\Domain\Payouts\PayoutCalculator;
use App\Enums\PayoutCase;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\NextOfKin;
use App\Models\Payout;
use App\Support\Kwacha;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Closing members out at the end of the cycle.
 *
 * Both screens read; neither writes. The breakdown shown on the detail page is
 * computed fresh from the ledgers every time it is opened, and recomputed again inside
 * PayoutExecutor when the committee signs — so what is stored is never a figure that
 * travelled through a browser.
 */
class ClosureController extends Controller
{
    public function __construct(
        protected ClosureRegister $register,
        protected PayoutCalculator $calculator,
    ) {}

    /** Everyone waiting to be settled, exits first, with what each would come to. */
    public function index(Request $request, CurrentCycle $currentCycle): Response
    {
        $this->authorize('viewAny', Payout::class);

        $cycle = $currentCycle->get();

        if ($cycle === null) {
            return Inertia::render('app/closures/Index', [
                'cycle' => null,
                'pending' => [],
                'settled' => [],
            ]);
        }

        return Inertia::render('app/closures/Index', [
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status,
                'status_label' => $cycle->status->label(),
                'is_sharing_out' => $cycle->status->isSharingOut(),
            ],
            'pending' => $this->register->pending($cycle)->all(),
            'settled' => $this->register->settled($cycle)->all(),
        ]);
    }

    /** One member's closure: the statement, the linked grant, and what may be done. */
    public function show(Request $request, Member $member): Response
    {
        $this->authorize('viewAny', Payout::class);

        $user = $request->user();
        $cycle = $member->cycle;
        $case = PayoutCase::forStatus($member->status);
        $payout = $member->payout()->with('executedBy', 'secondApprover')->first();

        /* A settled member's statement is the stored one — the ledgers have moved on. */
        $breakdown = $payout === null
            ? $this->calculator->for($member)->toArray()
            : $payout->breakdown;

        return Inertia::render('app/closures/Show', [
            'member' => [
                'id' => $member->id,
                'member_number' => $member->member_number,
                'full_name' => $member->full_name,
                'phone' => $member->phone,
                'status' => $member->status,
                'status_label' => $member->status->label(),
                'status_reason' => $member->status_reason,
                'status_effective_on' => $member->status_effective_on?->toDateString(),
                'date_of_death' => $member->date_of_death?->toDateString(),
                'ledgers_frozen_at' => $member->ledgers_frozen_at?->toIso8601String(),
            ],
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status,
                'status_label' => $cycle->status->label(),
                'is_sharing_out' => $cycle->status->isSharingOut(),
            ],
            'payoutCase' => ['value' => $case, 'label' => $case->label()],
            'breakdown' => $breakdown,
            'closure' => $this->register->row($member),
            'payout' => $payout === null ? null : [
                'id' => $payout->id,
                'amount_ngwee' => Kwacha::toNgwee($payout->amount_ngwee),
                'executed_at' => $payout->executed_at?->toIso8601String(),
                'executed_by' => $payout->executedBy?->full_name,
                'second_approver' => $payout->secondApprover?->full_name,
                'early_settlement_note' => $payout->early_settlement_note,
                'note' => $payout->note,
            ],
            'debt' => $this->debtPayload($member),
            'arrangement' => $this->arrangementPayload($member),
            'nextOfKin' => $member->nextOfKin()->get()->map(fn (NextOfKin $kin): array => [
                'id' => $kin->id,
                'name' => $kin->name,
                'phone' => $kin->phone,
                'relationship_label' => $kin->relationshipLabel(),
            ])->all(),
            'abilities' => [
                'execute' => $user->can('execute', [Payout::class, $member]),
                'settleEarly' => $user->can('settleEarly', [Payout::class, $member]),
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    protected function debtPayload(Member $member): ?array
    {
        $debt = $member->debt()->first();

        return $debt === null ? null : [
            'id' => $debt->id,
            'amount_owed_ngwee' => Kwacha::toNgwee($debt->amount_owed_ngwee),
            'status' => $debt->status,
            'status_label' => $debt->status->label(),
            'note' => $debt->note,
        ];
    }

    /** @return array<string, mixed>|null */
    protected function arrangementPayload(Member $member): ?array
    {
        $arrangement = $member->repaymentArrangement()->with('nextOfKin')->first();

        return $arrangement === null ? null : [
            'id' => $arrangement->id,
            'amount_owed_ngwee' => Kwacha::toNgwee($arrangement->amount_owed_ngwee),
            'agreed_terms' => $arrangement->agreed_terms,
            'agreed_on' => $arrangement->agreed_on?->toDateString(),
            'status' => $arrangement->status,
            'status_label' => $arrangement->status->label(),
            'next_of_kin' => $arrangement->nextOfKin?->name,
            'note' => $arrangement->note,
        ];
    }
}
