<?php

namespace App\Http\Middleware;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Declarations\DeclarationWindow;
use App\Domain\Payments\PaymentGateway;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Middleware;

/**
 * Shares the authorisation and cycle context every page in the portal relies on.
 *
 * The permissions listed here are for rendering only — they decide what a user is
 * shown, never what they may do. Every action stays guarded by a policy or by
 * permission middleware on the route.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => fn (): ?array => $this->user($request->user()),
            ],
            'currentCycle' => fn (): ?array => $this->cycle(),

            /*
             * What the browser needs to open the provider's hosted payment widget: the
             * public key and the script URL, and nothing else. The API token — which
             * moves money out of the group's account — never leaves the server. Null
             * when no gateway is configured, which is what the pay buttons check.
             */
            'payments' => fn (): ?array => $this->paymentWidget(),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array{key: string, script: string, channels: array<int, string>}|null
     */
    protected function paymentWidget(): ?array
    {
        return app(PaymentGateway::class)->widgetConfig();
    }

    /**
     * The signed-in user, with the member record and abilities the UI renders from.
     *
     * @return array<string, mixed>|null
     */
    protected function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $member = Member::query()->where('user_id', $user->id)->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => null,
            'email_verified_at' => $user->email_verified_at,
            'member_id' => $member?->id,
            'member_number' => $member?->member_number,
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
        ];
    }

    /**
     * The running cycle and the state of this month's declaration and trading windows.
     *
     * @return array<string, mixed>|null
     */
    protected function cycle(): ?array
    {
        $cycle = app(CurrentCycle::class)->get();

        if (! $cycle instanceof Cycle) {
            return null;
        }

        $month = $this->currentMonth($cycle);

        return [
            'id' => $cycle->id,
            'name' => $cycle->name,
            'status' => $cycle->status,
            'starts_on' => $cycle->starts_on->toDateString(),
            'ends_on' => $cycle->ends_on->toDateString(),
            'final_repayment_date' => $cycle->final_repayment_date->toDateString(),
            'days_to_final_repayment' => $cycle->daysToFinalRepayment(),
            'min_savings_ngwee' => $cycle->min_savings_ngwee->getMinorAmount()->toInt(),
            'savings_increment_ngwee' => $cycle->savings_increment_ngwee->getMinorAmount()->toInt(),
            'lockdown_savings_cap_ngwee' => $cycle->lockdown_savings_cap_ngwee->getMinorAmount()->toInt(),
            'is_lockdown' => $month !== null && $cycle->isLockdownMonth($month->sequence),
            'month' => $month === null ? null : $this->month($month),
        ];
    }

    /**
     * This calendar month's row in the cycle, when the cycle is currently running.
     */
    protected function currentMonth(Cycle $cycle): ?CycleMonth
    {
        return CycleMonth::query()
            ->where('cycle_id', $cycle->id)
            ->whereDate('month', Carbon::now()->startOfMonth())
            ->first();
    }

    /**
     * Where in the month we are, read from the one service that decides it.
     *
     * The declaration screens and the trading console ask DeclarationWindow the same
     * question, so the banner in the shell can never disagree with the form under it.
     *
     * @return array<string, mixed>
     */
    protected function month(CycleMonth $month): array
    {
        return app(DeclarationWindow::class)->payload($month);
    }
}
