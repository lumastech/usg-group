<?php

use App\Http\Controllers\App\CollateralClaimController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\LoanApprovalController;
use App\Http\Controllers\App\LoanController;
use App\Http\Controllers\App\LoanDefaultController;
use App\Http\Controllers\App\LoanDisbursementController;
use App\Http\Controllers\App\LoanEligibilityController;
use App\Http\Controllers\App\LoanExportController;
use App\Http\Controllers\App\LoanMatrixController;
use App\Http\Controllers\App\LoanRepaymentController;
use App\Http\Controllers\App\LoanTargetController;
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

    /*
     * Lending. Reading the register and the workbook views needs only a reporting
     * permission, but the three writes are deliberately split across three offices:
     * the chair's approves, the treasurer's pays out and takes repayments, and neither
     * can do the other's half. The named routes below the collection must stay after
     * the literal ones, or /app/loans/queue resolves as a loan id.
     */
    Route::get('loans', [LoanController::class, 'index'])->name('loans.index');
    Route::get('loans/matrix', LoanMatrixController::class)->name('loans.matrix');
    Route::get('loans/export/{format}', LoanExportController::class)
        ->whereIn('format', ['xlsx', 'pdf'])
        ->name('loans.export');
    Route::get('loans/targets', LoanTargetController::class)->name('loans.targets');

    Route::middleware('permission:loans.request')->group(function () {
        Route::get('loans/request', [LoanController::class, 'create'])->name('loans.create');
        Route::post('loans', [LoanController::class, 'store'])->name('loans.store');
        Route::post('loans/eligibility', LoanEligibilityController::class)->name('loans.eligibility');
    });

    Route::middleware('permission:loans.disburse')->group(function () {
        Route::get('loans/queue', [LoanDisbursementController::class, 'index'])->name('loans.queue');
        Route::post('loans/{loan}/disburse', [LoanDisbursementController::class, 'store'])->name('loans.disburse');
    });

    Route::get('loans/{loan}', [LoanController::class, 'show'])->name('loans.show');

    Route::post('loans/{loan}/repayments', LoanRepaymentController::class)
        ->middleware('permission:loans.record-repayment')
        ->name('loans.repayments.store');

    /*
     * Approval, default and collateral all sit with the office that approves lending,
     * and each additionally carries a second signature checked inside the domain.
     */
    Route::middleware('permission:loans.approve')->group(function () {
        Route::post('loans/{loan}/approve', [LoanApprovalController::class, 'store'])->name('loans.approve');
        Route::delete('loans/{loan}/approve', [LoanApprovalController::class, 'destroy'])->name('loans.reject');
        Route::post('loans/{loan}/default', LoanDefaultController::class)->name('loans.default');
        Route::post('loans/{loan}/collateral', [CollateralClaimController::class, 'store'])->name('loans.collateral.store');
        Route::post('collateral/{claim}/sign-off', [CollateralClaimController::class, 'signOff'])->name('collateral.sign-off');
        Route::post('collateral/{claim}/enforce', [CollateralClaimController::class, 'enforce'])->name('collateral.enforce');
        Route::post('collateral/{claim}/release', [CollateralClaimController::class, 'release'])->name('collateral.release');
    });
});
