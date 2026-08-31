<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\TransactionSource;
use App\Enums\WalletEntryType;
use App\Exceptions\ImmutableLedgerException;
use App\Models\Concerns\BelongsToCycle;
use Brick\Money\Money;
use Database\Factories\WalletEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One movement on a wallet.
 *
 * The amount is signed — credits positive, debits negative — so a balance is the sum of
 * this column and nothing else caches it.
 *
 * Append-only, exactly as the savings and social fund ledgers are. A top-up credited in
 * error is undone by a Reversal pointing at the entry it undoes, so both the mistake
 * and the correction stay on the record the member reads.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int $wallet_id
 * @property Money $amount_ngwee
 * @property WalletEntryType $type
 * @property int|null $wallet_transfer_id
 * @property int|null $payment_intent_id
 * @property int|null $counterparty_wallet_id
 * @property string|null $posted_ledger_type
 * @property int|null $posted_ledger_id
 * @property int|null $reverses_wallet_entry_id
 * @property TransactionSource $source
 * @property Carbon $occurred_on
 * @property string|null $note
 * @property int|null $recorded_by_member_id
 * @property int|null $second_approver_member_id
 */
#[Fillable([
    'cycle_id', 'wallet_id', 'amount_ngwee', 'type', 'wallet_transfer_id',
    'payment_intent_id', 'counterparty_wallet_id', 'posted_ledger_type', 'posted_ledger_id',
    'reverses_wallet_entry_id', 'source', 'occurred_on', 'note',
    'recorded_by_member_id', 'second_approver_member_id',
])]
class WalletEntry extends Model
{
    /** @use HasFactory<WalletEntryFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /**
     * The wallet ledger is append-only, like every other ledger in this system.
     *
     * This is not a formality. Invariant 1 — that every wallet balance together equals
     * the money the group actually holds — can only be checked if an entry, once
     * written, stays written.
     */
    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            throw new ImmutableLedgerException(
                'Wallet entries cannot be edited. Post a reversing entry instead.'
            );
        });

        static::deleting(function (self $entry): void {
            throw new ImmutableLedgerException(
                'Wallet entries cannot be deleted. Post a reversing entry instead.'
            );
        });
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<Wallet, $this> */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'counterparty_wallet_id');
    }

    /** @return BelongsTo<WalletTransfer, $this> */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(WalletTransfer::class, 'wallet_transfer_id');
    }

    /** @return BelongsTo<PaymentIntent, $this> */
    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    /** @return BelongsTo<self, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_wallet_entry_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'recorded_by_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'second_approver_member_id');
    }

    /**
     * The ledger row the money this entry moved ended up in.
     *
     * Null while the money is still standing in the wallet, and null for the savings
     * half of a trading-sheet payment until the month is concluded — the sheet is
     * marked, not posted, and `TradingConcluder` decides when.
     *
     * @return MorphTo<Model, $this>
     */
    public function postedLedger(): MorphTo
    {
        return $this->morphTo('posted_ledger');
    }

    public function isCredit(): bool
    {
        return $this->getRawOriginal('amount_ngwee') > 0;
    }

    /**
     * Entries that put money in.
     *
     * @param  Builder<static>  $query
     */
    public function scopeCredits(Builder $query): void
    {
        $query->where('amount_ngwee', '>', 0);
    }

    /**
     * Entries that took money out.
     *
     * @param  Builder<static>  $query
     */
    public function scopeDebits(Builder $query): void
    {
        $query->where('amount_ngwee', '<', 0);
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
            'type' => WalletEntryType::class,
            'source' => TransactionSource::class,
            'occurred_on' => 'date',
        ];
    }
}
