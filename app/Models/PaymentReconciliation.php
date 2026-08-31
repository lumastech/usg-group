<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Models\Concerns\BelongsToCycle;
use Brick\Money\Money;
use Database\Factories\PaymentReconciliationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day's comparison of what the provider moved against what the ledgers took.
 *
 * The interesting column is `unmatched`: a payment the provider knows about that we
 * never posted, or a posted payment it has no record of. Everything else is context for
 * reading it.
 *
 * @property int $id
 * @property int|null $cycle_id
 * @property Carbon $for_date
 * @property int $collections_count
 * @property Money $collections_ngwee
 * @property int $transfers_count
 * @property Money $transfers_ngwee
 * @property Money $fees_ngwee
 * @property Money|null $provider_balance_ngwee
 * @property array<int, array<string, mixed>>|null $unmatched
 * @property int $unmatched_count
 * @property Money|null $wallet_variance_ngwee
 * @property Money|null $group_wallet_variance_ngwee
 * @property array<string, mixed>|null $wallet_invariants
 * @property int|null $run_by_member_id
 * @property Carbon $ran_at
 */
#[Fillable([
    'cycle_id', 'for_date', 'collections_count', 'collections_ngwee', 'transfers_count',
    'transfers_ngwee', 'fees_ngwee', 'provider_balance_ngwee', 'unmatched',
    'unmatched_count', 'wallet_variance_ngwee', 'group_wallet_variance_ngwee',
    'wallet_invariants', 'run_by_member_id', 'ran_at',
])]
class PaymentReconciliation extends Model
{
    /** @use HasFactory<PaymentReconciliationFactory> */
    use BelongsToCycle, HasFactory;

    /** @return BelongsTo<Member, $this> */
    public function runBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'run_by_member_id');
    }

    /** Whether both sides agreed, with nothing left over. */
    public function agrees(): bool
    {
        return $this->unmatched_count === 0;
    }

    /**
     * Whether the wallet float is fully backed by money the group actually holds.
     *
     * Invariant 1. A mismatch is an alarm: it is the only check standing between the
     * group and a float that quietly does not exist.
     */
    public function walletsBalance(): bool
    {
        if ($this->wallet_variance_ngwee === null) {
            return true;
        }

        $tolerance = (int) config('wallets.reconciliation.tolerance_ngwee', 0);

        return abs($this->wallet_variance_ngwee->getMinorAmount()->toInt()) <= $tolerance;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'for_date' => 'date',
            'ran_at' => 'datetime',
            'collections_ngwee' => MoneyCast::class,
            'transfers_ngwee' => MoneyCast::class,
            'fees_ngwee' => MoneyCast::class,
            'provider_balance_ngwee' => MoneyCast::class,
            'unmatched' => 'array',
            'wallet_variance_ngwee' => MoneyCast::class,
            'group_wallet_variance_ngwee' => MoneyCast::class,
            'wallet_invariants' => 'array',
        ];
    }
}
