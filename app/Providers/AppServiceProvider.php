<?php

namespace App\Providers;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Loans\MonthlyInterestIncome;
use App\Domain\Loans\NoInterestIncome;
use App\Domain\Loans\NoOutstandingLoans;
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

        // Replaced by the lending engine in module 3; until then nobody owes anything.
        $this->app->bind(OutstandingLoanProvider::class, NoOutstandingLoans::class);
        $this->app->bind(MonthlyInterestIncome::class, NoInterestIncome::class);
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
