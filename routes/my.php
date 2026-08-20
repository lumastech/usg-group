<?php

use App\Http\Controllers\My\DashboardController;
use App\Http\Controllers\My\LoanController;
use App\Http\Controllers\My\ProfileController;
use App\Http\Controllers\My\SavingsController;
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

    Route::get('profile', [ProfileController::class, 'show'])->name('profile');
    Route::put('profile/{member}', [ProfileController::class, 'update'])->name('profile.update');
});
