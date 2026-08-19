<?php

namespace App\Enums;

/**
 * How a month's interest is credited to members.
 *
 * The opening month of a cycle credits each member a flat percentage of their own
 * savings. Every month after that pools all loan interest charged group-wide and
 * splits it pro-rata by each member's cumulative savings.
 */
enum InterestAllocationMethod: string
{
    case OwnSavingsFlat = 'own_savings_flat';
    case PooledProRata = 'pooled_pro_rata';
}
