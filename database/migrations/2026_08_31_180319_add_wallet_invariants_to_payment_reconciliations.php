<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily wallet invariants, kept beside the payment reconciliation they belong with.
 *
 * Invariant 1 is the single strongest audit control the system has: it is the first
 * check that catches a fraud requiring no ledger tampering at all. A wallet credited
 * with no money behind it shows up here the next morning, and nowhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_reconciliations', function (Blueprint $table): void {
            /*
             * Invariant 1: sum(all wallet balances) == provider balance + cash tin −
             * withdrawals in flight. Non-zero is an alarm, not a report.
             */
            $table->bigInteger('wallet_variance_ngwee')->nullable()->after('unmatched_count');

            /*
             * Invariant 2, the weaker one: the group wallet against what the ledgers say
             * the group should be holding. Reported rather than alarmed — the group
             * wallet opens at a recorded float rather than being derived from history,
             * so this drifts by construction until a cycle has run wholly on wallets.
             */
            $table->bigInteger('group_wallet_variance_ngwee')->nullable()->after('wallet_variance_ngwee');

            /* The figures behind both, for the dashboard to show the working. */
            $table->json('wallet_invariants')->nullable()->after('group_wallet_variance_ngwee');
        });
    }

    public function down(): void
    {
        Schema::table('payment_reconciliations', function (Blueprint $table): void {
            $table->dropColumn(['wallet_variance_ngwee', 'group_wallet_variance_ngwee', 'wallet_invariants']);
        });
    }
};
