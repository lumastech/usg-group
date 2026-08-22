<?php

namespace App\Providers;

use App\Domain\Cycles\CurrentCycle;
use App\Domain\Loans\LedgerInterestIncome;
use App\Domain\Loans\LedgerOutstandingLoans;
use App\Domain\Loans\MonthlyInterestIncome;
use App\Domain\Loans\OutstandingLoanProvider;
use App\Domain\Notifications\Sms\SmsGateway;
use App\Domain\Payments\Lenco\LencoGateway;
use App\Domain\Payments\NullPaymentGateway;
use App\Domain\Payments\PaymentGateway;
use App\Domain\Payouts\NoRounding;
use App\Domain\Payouts\RoundingPolicy;
use App\Notifications\Channels\SmsChannel;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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

        /*
         * The workbook's ROUNDOFF ADJSTMNT column. The group has not settled on a
         * convention, so nothing is rounded and net payable equals net value to the
         * ngwee. Adopting one — say RoundDownToStep(5_000) for whole K50 notes — is a
         * change of this line; the statement, the voucher, the payouts row and the
         * remainder posting to the Social Fund already carry the adjustment.
         */
        $this->app->bind(RoundingPolicy::class, NoRounding::class);

        /*
         * The SMS seam. Nothing in the application names a provider — notifications
         * build an SmsMessage and the channel hands it to whatever is bound here, so
         * signing up with Africa's Talking is a new class plus one config value. The
         * default writes the message to the log, which keeps the whole preference and
         * channel path exercised while the group has no account.
         */
        $this->app->bind(SmsGateway::class, fn ($app) => $app->make(
            (string) config('notifications.sms.gateway'),
        ));

        /*
         * The payment seam, and the same bargain as the SMS one: nothing above
         * App\Domain\Payments names a provider. The default moves no money and writes
         * what it would have moved to the log, so intents, the state machine, the
         * poller and every posting rule are exercised before the group holds a Lenco
         * account. PAYMENT_GATEWAY=lenco is the whole switch-over.
         */
        $this->app->bind(PaymentGateway::class, fn ($app): PaymentGateway => match (config('payments.default')) {
            'lenco' => $app->make(LencoGateway::class),
            default => $app->make(NullPaymentGateway::class),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Notification::extend('sms', fn ($app): SmsChannel => $app->make(SmsChannel::class));
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
