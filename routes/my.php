<?php

use App\Http\Controllers\My\DashboardController;
use App\Http\Controllers\My\LoanController;
use App\Http\Controllers\My\ProfileController;
use App\Http\Controllers\My\SavingsController;
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

    Route::get('profile', [ProfileController::class, 'show'])->name('profile');
    Route::put('profile/{member}', [ProfileController::class, 'update'])->name('profile.update');
});
