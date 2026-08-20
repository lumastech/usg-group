<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\TradingSession;
use App\Models\User;

/**
 * Who may work the trading console.
 *
 * Watching the day is open to anyone who may read the group's figures — the sheet is
 * read out at the table — but marking money and concluding the session belong to
 * `trading.operate` alone, because concluding is what posts the month.
 */
class TradingSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::TradingOperate->value,
            Permission::DeclarationsView->value,
            Permission::ReportsView->value,
        ]);
    }

    public function view(User $user, TradingSession $session): bool
    {
        return $this->viewAny($user);
    }

    /** Marking receipts and confirming disbursements at the table. */
    public function operate(User $user): bool
    {
        return $user->can(Permission::TradingOperate->value);
    }

    /** Concluding: only once, and only while the session is still open. */
    public function conclude(User $user, TradingSession $session): bool
    {
        return $this->operate($user) && $session->isOpen();
    }
}
