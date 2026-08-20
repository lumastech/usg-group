<?php

namespace App\Providers;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Loans\LedgerInterestIncome;
use App\Domain\Loans\LedgerOutstandingLoans;
use App\Domain\Loans\MonthlyInterestIncome;
use App\Domain\Loans\OutstandingLoanProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentCycle::class);

        /*
         * The two seams the savings module reads the loan world through. Module 3 swaps
         * the null implementations for the ledger-backed ones here rather than touching
         * anything in app/Domain/Savings.
         */
        $this->app->bind(OutstandingLoanProvider::class, LedgerOutstandingLoans::class);
        $this->app->bind(MonthlyInterestIncome::class, LedgerInterestIncome::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Inertia pages read props directly, so a single resource is not wrapped in
        // "data". Paginated collections keep their data/meta envelope regardless.
        JsonResource::withoutWrapping();

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
