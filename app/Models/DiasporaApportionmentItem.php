<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\ApportionmentItemStatus;
use App\Policies\DiasporaApportionmentPolicy;
use Brick\Money\Money;
use Database\Factories\DiasporaApportionmentItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One diaspora member's share of an apportionment, and whether it has been sent.
 *
 * @property int $id
 * @property int $diaspora_apportionment_id
 * @property int $member_id
 * @property Money $amount_ngwee
 * @property ApportionmentItemStatus $status
 * @property Carbon|null $paid_on
 * @property string|null $reference
 */
#[Fillable([
    'diaspora_apportionment_id', 'member_id', 'amount_ngwee', 'status',
    'paid_on', 'confirmed_by_member_id', 'reference',
])]
#[UsePolicy(DiasporaApportionmentPolicy::class)]
class DiasporaApportionmentItem extends Model
{
    /** @use HasFactory<DiasporaApportionmentItemFactory> */
    use HasFactory, LogsActivity;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ApportionmentItemStatus::Pending->value,
        'amount_ngwee' => 0,
    ];

    /** @return BelongsTo<DiasporaApportionment, $this> */
    public function apportionment(): BelongsTo
    {
        return $this->belongsTo(DiasporaApportionment::class, 'diaspora_apportionment_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'confirmed_by_member_id');
    }

    /** The outflow this share produced, once the transfer was confirmed. */
    public function payment(): MorphOne
    {
        return $this->morphOne(SocialFundTransaction::class, 'reference');
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
            'status' => ApportionmentItemStatus::class,
            'paid_on' => 'date',
        ];
    }
}
