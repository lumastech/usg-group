<?php

namespace App\Http\Controllers\App;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Wallets\TopUpService;
use App\Domain\Wallets\WalletLedger;
use App\Domain\Wallets\WalletReconciler;
use App\Domain\Wallets\WalletRegistry;
use App\Domain\Wallets\WithdrawalService;
use App\Enums\Permission;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallets\StoreCashMovementRequest;
use App\Http\Resources\WalletEntryResource;
use App\Http\Resources\WalletResource;
use App\Models\Wallet;
use App\Support\Kwacha;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The committee's view of the wallet float.
 *
 * Two things are worth the committee's attention here and nothing else is. What the
 * group owes its members on demand — a liability, never group funds, and never part of
 * the savings pool or the social fund balance. And whether that float is backed by
 * money the group actually holds, which is the single strongest audit control in the
 * system: a wallet credited with nothing behind it needs no ledger tampering at all and
 * shows up nowhere else.
 */
class WalletController extends Controller
{
    public function __construct(
        protected WalletRegistry $wallets,
        protected WalletLedger $ledger,
        protected TopUpService $topUps,
        protected WithdrawalService $withdrawals,
        protected WalletReconciler $reconciler,
        protected CurrentCycle $currentCycle,
    ) {}

    public function index(Request $request): Response
    {
        $cycle = $this->currentCycle->getOrFail();

        $wallets = Wallet::query()
            ->forCycle($cycle)
            ->memberOwned()
            ->with('member')
            ->get()
            ->sortBy(fn (Wallet $wallet): string => $wallet->member->full_name)
            ->values();

        $group = $this->wallets->group($cycle);

        return Inertia::render('app/wallets/Index', [
            'wallets' => WalletResource::collection($wallets),
            'group' => new WalletResource($group),
            'invariants' => $this->reconciler->check($cycle),
            'abilities' => [
                'recordCash' => $request->user()->can(Permission::PaymentsInitiate->value),
                'reconcile' => $request->user()->can(Permission::PaymentsReconcile->value),
            ],
        ]);
    }

    /** One member's wallet, and everything that has moved through it. */
    public function show(Request $request, Wallet $wallet): Response
    {
        return Inertia::render('app/wallets/Show', [
            'wallet' => new WalletResource($wallet->load('member')),
            'statement' => WalletEntryResource::collection(
                $this->ledger->statement($wallet)->limit(200)->get()
            ),
            'abilities' => [
                'recordCash' => $request->user()->can(Permission::PaymentsInitiate->value),
            ],
        ]);
    }

    /** Banknotes counted at the table, into a member's wallet. */
    public function cashIn(StoreCashMovementRequest $request): RedirectResponse
    {
        $member = $request->member();

        try {
            $this->topUps->inCash(
                $member,
                Kwacha::ofNgwee($request->integer('amount_ngwee')),
                $request->user()->actingMember(),
                note: $request->input('note'),
            );
        } catch (DomainRuleException $exception) {
            throw ValidationException::withMessages(['amount_ngwee' => $exception->getMessage()]);
        }

        return back()->with('success', "Cash recorded into {$member->full_name}'s wallet.");
    }

    /**
     * Banknotes handed across the table, out of a member's wallet.
     *
     * Two signatures whatever the amount — the committee's decision, minuted, and
     * stricter than the fund's threshold rule on purpose.
     */
    public function cashOut(StoreCashMovementRequest $request): RedirectResponse
    {
        $member = $request->member();

        try {
            $this->withdrawals->payCash(
                $member,
                Kwacha::ofNgwee($request->integer('amount_ngwee')),
                $request->user()->actingMember(),
                $request->confirmer(),
            );
        } catch (DomainRuleException $exception) {
            throw ValidationException::withMessages(['amount_ngwee' => $exception->getMessage()]);
        }

        return back()->with('success', "Cash paid out of {$member->full_name}'s wallet.");
    }
}
