<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\WalletTransferPurpose;
use App\Exceptions\ImmutableLedgerException;
use App\Models\Concerns\BelongsToCycle;
use Brick\Money\Money;
use Database\Factories\WalletTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One movement of money between two wallets.
 *
 * A transfer writes exactly two entries, in one database transaction, and never one
 * without the other. That pairing is the double-entry guarantee, and it is the reason
 * the reconciliation invariant can hold at all: no transfer can create or destroy
 * money, only move it.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $from_wallet_id
 * @property int $to_wallet_id
 * @property Money $amount_ngwee
 * @property WalletTransferPurpose $purpose
 * @property string|null $payable_type
 * @property int|null $payable_id
 * @property int|null $approved_by_member_id
 * @property int|null $second_approver_member_id
 * @property int|null $created_by_member_id
 * @property Carbon $occurred_at
 * @property string|null $note
 */
#[Fillable([
    'cycle_id', 'from_wallet_id', 'to_wallet_id', 'amount_ngwee', 'purpose',
    'payable_type', 'payable_id', 'approved_by_member_id', 'second_approver_member_id',
    'created_by_member_id', 'occurred_at', 'note',
])]
class WalletTransfer extends Model
{
    /** @use HasFactory<WalletTransferFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /**
     * A transfer is as immutable as the two entries it wrote.
     *
     * Editing one would leave the pair describing a movement its entries do not.
     */
    protected static function booted(): void
    {
        static::updating(function (self $transfer): void {
            throw new ImmutableLedgerException(
                'Wallet transfers cannot be edited. Reverse the transfer instead.'
            );
        });

        static::deleting(function (self $transfer): void {
            throw new ImmutableLedgerException(
                'Wallet transfers cannot be deleted. Reverse the transfer instead.'
            );
        });
    }

    /** @return BelongsTo<Wallet, $this> */
    public function fromWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'from_wallet_id');
    }

    /** @return BelongsTo<Wallet, $this> */
    public function toWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'to_wallet_id');
    }

    /** @return HasMany<WalletEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'approved_by_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'second_approver_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'created_by_member_id');
    }

    /**
     * What the money was for: a Declaration, a Loan, a Payout, a grant claim.
     *
     * @return MorphTo<Model, $this>
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_ngwee' => MoneyCast::class,
            'purpose' => WalletTransferPurpose::class,
            'occurred_at' => 'datetime',
        ];
    }
}
