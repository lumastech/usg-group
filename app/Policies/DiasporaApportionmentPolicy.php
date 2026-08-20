<?php

namespace App\Policies;

use App\Enums\ApportionmentItemStatus;
use App\Enums\Permission;
use App\Models\DiasporaApportionment;
use App\Models\DiasporaApportionmentItem;
use App\Models\User;

/**
 * Who may split money across the members abroad, and who may tick the transfers off.
 *
 * Declaring the split is an outflow decision and needs the approving permission plus
 * the second signature the service checks. Confirming an individual transfer is the
 * treasurers' bookkeeping against a split already approved.
 */
class DiasporaApportionmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::FundView->value,
            Permission::FundRecord->value,
            Permission::FundApproveOutflow->value,
            Permission::ReportsView->value,
        ]);
    }

    public function view(User $user, DiasporaApportionment $apportionment): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::FundApproveOutflow->value);
    }

    public function confirmTransfer(User $user, DiasporaApportionmentItem $item): bool
    {
        return $user->can(Permission::FundRecord->value)
            && $item->status === ApportionmentItemStatus::Pending;
    }
}
