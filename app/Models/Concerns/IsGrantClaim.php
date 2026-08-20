<?php

namespace App\Models\Concerns;

use App\Enums\GrantClaimStatus;
use App\Enums\SocialFundTransactionType;
use App\Models\Member;
use App\Models\SocialFundTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * The parts a funeral claim and a unity baby claim share.
 *
 * Both run Submitted → Approved → Paid with two distinct committee signatures, and
 * both post their outflow only at the moment of payment, so GrantClaimService can
 * drive either one through the same code.
 *
 * @phpstan-require-extends Model
 */
trait IsGrantClaim
{
    /** @return BelongsTo<Member, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** @return BelongsTo<Member, $this> */
    public function firstApprover(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'first_approver_member_id');
    }

    /** @return BelongsTo<Member, $this> */
    public function secondApprover(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'second_approver_member_id');
    }

    /** The outflow this claim produced, once it was paid. */
    public function payment(): MorphOne
    {
        return $this->morphOne(SocialFundTransaction::class, 'reference');
    }

    /** The ledger entry type a payment against this claim carries. */
    abstract public function grantType(): SocialFundTransactionType;

    /** How the claim reads on a statement, e.g. "Mary Banda (Parent)". */
    abstract public function subject(): string;

    public function isPayable(): bool
    {
        return $this->status === GrantClaimStatus::Approved;
    }
}
