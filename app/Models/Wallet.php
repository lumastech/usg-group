<?php

namespace App\Models;

use App\Enums\WalletKind;
use App\Enums\WalletStatus;
use App\Models\Concerns\BelongsToCycle;
use App\Support\Kwacha;
use Brick\Money\Money;
use Database\Factories\WalletFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Where a member's money is standing, between the provider and the group's ledgers.
 *
 * A wallet holds only money that is not yet committed to a ledger: money topped up and
 * not yet paid to the group, money the group has paid the member and they have not yet
 * withdrawn, and money returned by a failed withdrawal. The moment money becomes
 * savings it leaves the wallet — savings are locked until share-out by the
 * constitution and a wallet balance is not.
 *
 * The balance is never stored. It is the sum of the signed entries, so there is no
 * total anywhere to fall out of step with the ledger.
 *
 * @property int $id
 * @property int $cycle_id
 * @property int|null $member_id
 * @property WalletKind $kind
 * @property WalletStatus $status
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 */
#[Fillable(['cycle_id', 'member_id', 'kind', 'status', 'opened_at', 'closed_at'])]
class Wallet extends Model
{
    /** @use HasFactory<WalletFactory> */
    use BelongsToCycle, HasFactory, LogsActivity;

    /**
     * A new wallet is an open member wallet until told otherwise.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => WalletKind::Member->value,
        'status' => WalletStatus::Open->value,
    ];

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return HasMany<WalletEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WalletEntry::class);
    }

    /**
     * What the wallet holds, as a sum of its entries.
     *
     * Read outside a lock this is a snapshot and nothing more; every decision that
     * turns on the balance takes it inside `WalletLedger`'s row lock instead.
     */
    public function balance(): Money
    {
        return Kwacha::ofNgwee($this->balanceNgwee());
    }

    /**
     * The same as a raw integer, read regardless of which cycle is pinned.
     *
     * A member who does not rejoin still withdraws from the closed cycle's wallet, and
     * a pinned current cycle must not make that balance read as zero.
     */
    public function balanceNgwee(): int
    {
        return (int) $this->entries()->acrossCycles()->sum('amount_ngwee');
    }

    public function isGroupWallet(): bool
    {
        return $this->kind === WalletKind::Group;
    }

    public function isOpen(): bool
    {
        return $this->status === WalletStatus::Open;
    }

    /**
     * How this wallet is named on a statement or an audit line.
     *
     * A member wallet always has its member: the foreign key cascades on delete, so
     * the row cannot outlive them.
     */
    public function label(): string
    {
        return $this->isGroupWallet()
            ? 'the group wallet'
            : $this->member->full_name.'\'s wallet';
    }

    /**
     * Group wallets only.
     *
     * @param  Builder<static>  $query
     */
    public function scopeGroup(Builder $query): void
    {
        $query->where('kind', WalletKind::Group->value);
    }

    /**
     * Member wallets only.
     *
     * @param  Builder<static>  $query
     */
    public function scopeMemberOwned(Builder $query): void
    {
        $query->where('kind', WalletKind::Member->value);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => WalletKind::class,
            'status' => WalletStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
