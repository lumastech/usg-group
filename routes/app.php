<?php

use App\Http\Controllers\App\ClosureController;
use App\Http\Controllers\App\ClosureExecutionController;
use App\Http\Controllers\App\CollateralClaimController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\DeclarationController;
use App\Http\Controllers\App\DeclarationExportController;
use App\Http\Controllers\App\DiasporaApportionmentController;
use App\Http\Controllers\App\FuneralGrantClaimController;
use App\Http\Controllers\App\GrantClaimController;
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
use App\Http\Controllers\App\PayoutVoucherController;
use App\Http\Controllers\App\SavingsController;
use App\Http\Controllers\App\SavingsDepositController;
use App\Http\Controllers\App\SavingsExportController;
use App\Http\Controllers\App\SavingsStatementController;
use App\Http\Controllers\App\SocialFundContributionController;
use App\Http\Controllers\App\SocialFundController;
use App\Http\Controllers\App\SocialFundExportController;
use App\Http\Controllers\App\SocialFundLedgerController;
use App\Http\Controllers\App\SocialFundOutflowController;
use App\Http\Controllers\App\StyleguideController;
use App\Http\Controllers\App\TradingController;
use App\Http\Controllers\App\TradingEntryController;
use App\Http\Controllers\App\TradingSessionController;
use App\Http\Controllers\App\UnityBabyClaimController;
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
     * Declarations. Reading the month's sheet needs only a viewing permission — it is
     * read out at the table — while capturing one on somebody's behalf is the
     * treasurer's late-entry path and carries `declarations.record`.
     */
    Route::get('declarations', [DeclarationController::class, 'index'])->name('declarations.index');
    Route::get('declarations/export/{format}', DeclarationExportController::class)
        ->whereIn('format', ['xlsx', 'pdf'])
        ->name('declarations.export');

    Route::post('declarations', [DeclarationController::class, 'store'])
        ->middleware('permission:declarations.record')
        ->name('declarations.store');

    /*
     * The trading console. Watching the day is open to anyone who may read the
     * group's figures; every write, and above all concluding the session, belongs to
     * `trading.operate` — concluding is the act that posts the whole month.
     */
    Route::get('trading', [TradingController::class, 'index'])->name('trading.index');

    Route::middleware('permission:trading.operate')->group(function () {
        Route::post('trading/months/{month}/open', [TradingSessionController::class, 'store'])
            ->name('trading.open');
        Route::post('trading/sessions/{session}/conclude', [TradingSessionController::class, 'conclude'])
            ->name('trading.conclude');
        Route::post('trading/entries/{entry}/receipt', [TradingEntryController::class, 'store'])
            ->name('trading.entries.receipt');
        Route::delete('trading/entries/{entry}/receipt', [TradingEntryController::class, 'destroy'])
            ->name('trading.entries.receipt.destroy');
        Route::post('trading/entries/{entry}/disburse', [TradingEntryController::class, 'disburse'])
            ->name('trading.entries.disburse');
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
     * The Social Fund. Reading it is open to anyone who may read reports — it is the
     * group's own money for its bereavements and celebrations — while recording an
     * entry is the treasurers'. Nothing that takes money out is gated by a permission
     * alone: every outflow additionally carries a second signature checked inside the
     * domain, so `fund.approve-outflow` opens the dialog rather than the till.
     */
    Route::get('fund', [SocialFundController::class, 'index'])->name('fund.index');
    Route::get('fund/ledger', SocialFundLedgerController::class)->name('fund.ledger');
    Route::get('fund/export/{format}', SocialFundExportController::class)
        ->whereIn('format', ['xlsx', 'pdf'])
        ->name('fund.export');
    Route::get('fund/claims', GrantClaimController::class)->name('fund.claims');
    Route::get('fund/apportionment', [DiasporaApportionmentController::class, 'index'])->name('fund.apportionment');
    Route::post('fund/apportionment/preview', [DiasporaApportionmentController::class, 'preview'])
        ->name('fund.apportionment.preview');

    Route::middleware('permission:fund.record')->group(function () {
        Route::post('fund/contributions', SocialFundContributionController::class)->name('fund.contributions.store');
        Route::post('fund/apportionment/items/{item}/confirm', [DiasporaApportionmentController::class, 'confirm'])
            ->name('fund.apportionment.confirm');
    });

    /*
     * Claims may be raised by anyone the committee is recording for; approving, paying
     * and rejecting sit with the office that stands behind the fund's outflows.
     */
    Route::post('fund/claims/funeral', [FuneralGrantClaimController::class, 'store'])->name('fund.claims.funeral.store');
    Route::post('fund/claims/baby', [UnityBabyClaimController::class, 'store'])->name('fund.claims.baby.store');

    Route::middleware('permission:fund.approve-outflow')->group(function () {
        Route::post('fund/claims/funeral/{claim}/approve', [FuneralGrantClaimController::class, 'approve'])->name('fund.claims.funeral.approve');
        Route::post('fund/claims/funeral/{claim}/pay', [FuneralGrantClaimController::class, 'pay'])->name('fund.claims.funeral.pay');
        Route::post('fund/claims/funeral/{claim}/reject', [FuneralGrantClaimController::class, 'reject'])->name('fund.claims.funeral.reject');

        Route::post('fund/claims/baby/{claim}/approve', [UnityBabyClaimController::class, 'approve'])->name('fund.claims.baby.approve');
        Route::post('fund/claims/baby/{claim}/pay', [UnityBabyClaimController::class, 'pay'])->name('fund.claims.baby.pay');
        Route::post('fund/claims/baby/{claim}/reject', [UnityBabyClaimController::class, 'reject'])->name('fund.claims.baby.reject');

        Route::post('fund/entries', SocialFundOutflowController::class)->name('fund.entries.store');
        Route::post('fund/apportionment', [DiasporaApportionmentController::class, 'store'])->name('fund.apportionment.store');
    });

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
     * Closures. Reading who is owed what is open to anyone who may read the group's
     * figures — the register is read out at share-out — but executing one is
     * `payouts.execute` and additionally carries a second signature checked inside the
     * domain. Nothing here accepts an amount from the client: the position is
     * recomputed from the ledgers at the moment it is signed for.
     */
    Route::get('closures', [ClosureController::class, 'index'])->name('closures.index');
    Route::get('closures/{member}', [ClosureController::class, 'show'])->name('closures.show');
    Route::get('payouts/{payout}/voucher', PayoutVoucherController::class)->name('payouts.voucher');

    Route::post('closures/{member}', ClosureExecutionController::class)
        ->middleware('permission:payouts.execute')
        ->name('closures.execute');

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
