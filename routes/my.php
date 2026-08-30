<?php

use App\Http\Controllers\My\DashboardController;
use App\Http\Controllers\My\DeclarationController;
use App\Http\Controllers\My\DeclarationPaymentController;
use App\Http\Controllers\My\LoanController;
use App\Http\Controllers\My\PaymentController;
use App\Http\Controllers\My\PayoutDestinationController;
use App\Http\Controllers\My\ProfileController;
use App\Http\Controllers\My\SavingsController;
use App\Http\Controllers\My\SettingsController;
use App\Http\Controllers\My\SocialFundController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member portal
|--------------------------------------------------------------------------
|
| Everything under /my is a member acting on their own records. Routes here are
| scoped to the signed-in user's member record rather than gated by permission,
| with the exception of actions the constitution restricts.
|
*/

Route::middleware(['auth', 'verified'])->prefix('my')->name('my.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    /*
     * The month's declaration. The window rules live in DeclarationService, so the
     * route is open all month and the form itself locks — a member arriving on the 5th
     * is shown when the window next opens rather than a 403.
     */
    Route::get('declarations', [DeclarationController::class, 'show'])->name('declarations');
    Route::post('declarations', [DeclarationController::class, 'store'])
        ->middleware('permission:declarations.submit-own')
        ->name('declarations.store');

    /*
     * Paying the approved declaration without leaving the screen it was made on. The
     * amount is the committee's, not the member's, so nothing is posted with it.
     */
    Route::post('declarations/pay', [DeclarationPaymentController::class, 'store'])
        ->name('declarations.pay');

    Route::get('savings', SavingsController::class)->name('savings');
    Route::get('savings/statement', [SavingsController::class, 'statement'])->name('savings.statement');

    Route::get('loan', [LoanController::class, 'show'])->name('loan');
    Route::post('loan', [LoanController::class, 'store'])
        ->middleware('permission:loans.request')
        ->name('loan.store');

    /*
     * The member's own corner of the Social Fund: whether their K250 is in, and the
     * claims they have raised. A claim is checked against the signed-in member in the
     * controller, so there is no id here to point at somebody else.
     */
    Route::get('fund', [SocialFundController::class, 'show'])->name('fund');
    Route::post('fund/claims/funeral', [SocialFundController::class, 'storeFuneralClaim'])->name('fund.claims.funeral');
    Route::post('fund/claims/baby', [SocialFundController::class, 'storeBabyClaim'])->name('fund.claims.baby');

    /*
     * Paying what you owe, from your own phone. No permission is checked, in keeping
     * with the rest of /my — the route is scoped to the signed-in member's own record,
     * and paying your dues is not something the constitution restricts.
     */
    Route::get('payments', [PaymentController::class, 'index'])->name('payments');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::post('payments/{intent}/verify', [PaymentController::class, 'verify'])->name('payments.verify');

    /*
     * Where the member's money is sent. Guarded by the same policy as their contact
     * details — the account and the number it reaches are one decision.
     */
    Route::get('destinations', [PayoutDestinationController::class, 'index'])->name('destinations');
    Route::post('destinations', [PayoutDestinationController::class, 'store'])->name('destinations.store');
    Route::put('destinations/{destination}/default', [PayoutDestinationController::class, 'makeDefault'])
        ->name('destinations.default');
    Route::delete('destinations/{destination}', [PayoutDestinationController::class, 'destroy'])
        ->name('destinations.destroy');

    Route::get('profile', [ProfileController::class, 'show'])->name('profile');
    Route::put('profile/{member}', [ProfileController::class, 'update'])->name('profile.update');

    /*
     * How the member wants to be reached. Guarded by the same policy as their contact
     * details — the channel and the number it delivers to are one decision.
     */
    Route::get('settings', [SettingsController::class, 'show'])->name('settings');
    Route::put('settings/{member}', [SettingsController::class, 'update'])->name('settings.update');
});
