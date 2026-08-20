<?php

use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\MemberController;
use App\Http\Controllers\App\MemberInviteController;
use App\Http\Controllers\App\MemberStatusController;
use App\Http\Controllers\App\SavingsController;
use App\Http\Controllers\App\SavingsDepositController;
use App\Http\Controllers\App\SavingsExportController;
use App\Http\Controllers\App\SavingsStatementController;
use App\Http\Controllers\App\StyleguideController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Committee portal
|--------------------------------------------------------------------------
|
| Everything under /app. Each section is gated by the permission it needs, so
| authorisation is declared beside the route rather than inferred from a role.
| The UI mirrors these checks for rendering only — this is what enforces them.
|
*/

Route::middleware(['auth', 'verified'])->prefix('app')->name('app.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('styleguide', StyleguideController::class)->name('styleguide');

    /*
     * The register. Reading is gated by the policy (reports.view is enough), while
     * every write additionally passes the members.manage middleware, so a missing
     * policy check can never be the only thing standing between a role and a write.
     */
    Route::get('members', [MemberController::class, 'index'])->name('members.index');
    Route::get('members/create', [MemberController::class, 'create'])->name('members.create');
    Route::get('members/{member}', [MemberController::class, 'show'])->name('members.show');

    Route::middleware('permission:members.manage')->group(function () {
        Route::post('members', [MemberController::class, 'store'])->name('members.store');
        Route::get('members/{member}/edit', [MemberController::class, 'edit'])->name('members.edit');
        Route::put('members/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::put('members/{member}/status', MemberStatusController::class)->name('members.status');
        Route::post('members/{member}/invite', MemberInviteController::class)->name('members.invite');
    });

    /*
     * The savings ledger. Reading the matrix and the exports needs only a reporting
     * permission — the group checks each other's contributions — while recording a
     * deposit is the treasurers' alone, gated by savings.record on the write route
     * as well as by the policy the form request calls.
     */
    Route::get('savings', [SavingsController::class, 'index'])->name('savings.index');
    Route::get('savings/export/{format}', SavingsExportController::class)
        ->whereIn('format', ['xlsx', 'pdf'])
        ->name('savings.export');
    Route::get('savings/{member}', [SavingsController::class, 'show'])->name('savings.show');
    Route::get('savings/{member}/statement', SavingsStatementController::class)->name('savings.statement');

    Route::post('savings', SavingsDepositController::class)
        ->middleware('permission:savings.record')
        ->name('savings.store');
});
