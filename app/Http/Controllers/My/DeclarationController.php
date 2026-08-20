<?php

namespace App\Http\Controllers\My;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationService;
use App\Domain\Declarations\DeclarationWindow;
use App\Domain\Loans\LoanEligibilityService;
use App\Exceptions\DomainRuleException;
use App\Exceptions\LoanNotEligibleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Declarations\StoreDeclarationRequest;
use App\Http\Resources\DeclarationResource;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Declaration;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The member's own declaration for the month.
 *
 * This form is the only part of the system most of the group ever touches, so it opens
 * on the figures they are most likely to want — the minimum savings and the installment
 * the schedule already holds them to — and states plainly what the window is doing.
 */
class DeclarationController extends Controller
{
    public function __construct(
        protected DeclarationService $declarations,
        protected DeclarationWindow $window,
        protected LoanEligibilityService $eligibility,
    ) {}

    public function show(Request $request, CurrentCycle $currentCycle): Response
    {
        $member = $request->user()->member;
        $cycle = $currentCycle->get();
        $month = $cycle === null ? null : $this->monthFor($cycle);

        if ($member === null || $month === null) {
            return Inertia::render('my/Declarations', [
                'member' => null,
                'month' => null,
                'declaration' => null,
                'defaults' => null,
                'rules' => null,
                'eligibility' => null,
                'history' => [],
                'abilities' => ['submit' => false],
            ]);
        }

        $existing = $this->declarations->find($member, $month);
        $ceiling = $this->eligibility->ceilingFor($member, $month->trading_starts_on);

        return Inertia::render('my/Declarations', [
            'member' => [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'member_number' => $member->member_number,
            ],
            'month' => $this->window->payload($month),
            'declaration' => $existing === null ? null : new DeclarationResource($existing),
            'defaults' => $this->declarations->defaultsFor($member, $month),
            'rules' => $this->rules($cycle, $month),
            /* The same object the loan wizard renders, so the eligibility feedback
               under the request field reads identically in both places. */
            'eligibility' => $this->eligibility
                ->check($member, $ceiling, $month->trading_starts_on)
                ->toArray(),
            'history' => DeclarationResource::collection(
                Declaration::query()
                    ->where('member_id', $member->id)
                    ->with('cycleMonth')
                    ->get()
                    ->sortByDesc(fn (Declaration $row): int => $row->cycleMonth->sequence)
                    ->values(),
            ),
            'abilities' => [
                'submit' => $request->user()->can('submitOwn', [Declaration::class, $member]),
            ],
        ]);
    }

    /**
     * Captures the member's own declaration.
     *
     * Every refusal the domain can raise is turned into a field error rather than an
     * error page: a member on a phone needs to see which of the three amounts is the
     * problem, beside the box they typed it in.
     */
    public function store(StoreDeclarationRequest $request): RedirectResponse
    {
        $member = $request->member();
        $month = $request->month();

        try {
            $this->declarations->submit(
                $member,
                $month,
                Kwacha::ofNgwee($request->integer('saving_amount_ngwee')),
                Kwacha::ofNgwee($request->integer('loan_repayment_amount_ngwee')),
                Kwacha::ofNgwee($request->integer('loan_requested_amount_ngwee')),
                actor: $member,
            );
        } catch (LoanNotEligibleException $exception) {
            return back()->withErrors([
                'loan_requested_amount_ngwee' => implode(' ', $exception->reasons()),
            ]);
        } catch (DomainRuleException $exception) {
            return back()->withErrors([
                'saving_amount_ngwee' => $exception->getMessage(),
            ]);
        }

        return back()->with('success', "Your declaration for {$month->label()} has been recorded.");
    }

    /**
     * The month the member is declaring for: this calendar month, or the cycle's last.
     */
    protected function monthFor(Cycle $cycle): ?CycleMonth
    {
        return $cycle->monthFor(now());
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(Cycle $cycle, CycleMonth $month): array
    {
        return [
            'minimum_ngwee' => Kwacha::toNgwee($cycle->min_savings_ngwee),
            'increment_ngwee' => Kwacha::toNgwee($cycle->savings_increment_ngwee),
            'lockdown_cap_ngwee' => Kwacha::toNgwee($cycle->lockdown_savings_cap_ngwee),
            'lockdown_starts_month' => $cycle->loan_lockdown_starts_month,
            'is_lockdown' => $cycle->isLockdownMonth($month->sequence),
            'savings_cap_ngwee' => $cycle->savingsCapForMonth($month->sequence) === null
                ? null
                : Kwacha::toNgwee($cycle->savingsCapForMonth($month->sequence)),
        ];
    }
}
