<?php

namespace App\Domain\Members;

use App\Domain\Wallets\WalletRegistry;
use App\Enums\MemberStatus;
use App\Enums\NextOfKinRelationship;
use App\Exceptions\JoiningFeeBelowMinimumException;
use App\Exceptions\RegistrationClosedException;
use App\Models\Cycle;
use App\Models\Member;
use App\Support\Kwacha;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registers members into a cycle, enforcing the constitution's joining rules.
 *
 * Membership closes after the third month of the cycle, and anyone joining in the
 * third month pays the late registration fee instead of the standard joining fee.
 */
class MembershipRegistrar
{
    public const LATE_REGISTRATION_MONTH = 3;

    public function __construct(
        protected WalletRegistry $wallets,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Member columns, optionally with a
     *                                            `next_of_kin` list of nominee rows.
     */
    public function register(Cycle $cycle, array $attributes, ?CarbonInterface $joinedOn = null): Member
    {
        $joinedOn ??= Carbon::today();
        $sequence = $this->monthSequenceFor($cycle, $joinedOn);

        if (! $cycle->registrationOpenForMonth($sequence)) {
            throw new RegistrationClosedException(
                "Membership registration closed after month {$cycle->registration_closes_after_month} of the cycle."
            );
        }

        $minimum = $this->joiningFeeFor($cycle, $sequence);
        $paid = $this->paidAmount($attributes, $minimum);

        if ($paid->isLessThan($minimum)) {
            throw new JoiningFeeBelowMinimumException(sprintf(
                'The joining fee for month %d of the cycle is at least %s.',
                $sequence,
                Kwacha::format($minimum),
            ));
        }

        $nextOfKin = $attributes['next_of_kin'] ?? [];
        unset($attributes['next_of_kin'], $attributes['joining_fee_ngwee']);

        return DB::transaction(function () use ($cycle, $attributes, $joinedOn, $sequence, $paid, $nextOfKin): Member {
            $member = Member::create($attributes + [
                'cycle_id' => $cycle->id,
                'member_number' => $this->nextMemberNumber($cycle),
                'joined_on' => $joinedOn,
                'joining_month_sequence' => $sequence,
                'joining_fee_ngwee' => $paid,
                'status' => MemberStatus::Active,
                'status_changed_at' => Carbon::now(),
            ]);

            $this->syncNextOfKin($member, $nextOfKin);

            /* Opened with the member, at zero, so nothing downstream has to wonder
               whether a member has a wallet before it can pay them. */
            $this->wallets->forMember($member, $cycle);

            return $member;
        });
    }

    /**
     * Replace a member's nominees with the given rows.
     *
     * Nominees are rewritten wholesale rather than diffed: the form is a repeater,
     * so what was submitted is the complete list the member wants on record.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function syncNextOfKin(Member $member, array $rows): void
    {
        DB::transaction(function () use ($member, $rows): void {
            $member->nextOfKin()->delete();

            foreach ($rows as $row) {
                if (blank($row['name'] ?? null)) {
                    continue;
                }

                $label = $row['relationship_label'] ?? null;

                $member->nextOfKin()->create([
                    'name' => $row['name'],
                    'phone' => $row['phone'] ?? null,
                    'relationship' => $this->relationship($row),
                    'relationship_label' => $label,
                ]);
            }
        });
    }

    /** The standard fee, or the late registration fee from the third month onward. */
    public function joiningFeeFor(Cycle $cycle, int $sequence): Money
    {
        return $sequence >= self::LATE_REGISTRATION_MONTH
            ? $cycle->late_joining_fee_ngwee
            : $cycle->joining_fee_ngwee;
    }

    /** Which month of the cycle a date falls in, counting the cycle's first month as 1. */
    public function monthSequenceFor(Cycle $cycle, CarbonInterface $date): int
    {
        $start = $cycle->starts_on->copy()->startOfMonth();

        return (int) $start->diffInMonths($date->copy()->startOfMonth()) + 1;
    }

    /**
     * What the member actually paid, defaulting to the minimum for their tier.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function paidAmount(array $attributes, Money $minimum): Money
    {
        $paid = $attributes['joining_fee_ngwee'] ?? null;

        return match (true) {
            $paid instanceof Money => $paid,
            is_int($paid) => Kwacha::ofNgwee($paid),
            default => $minimum,
        };
    }

    /** @param  array<string, mixed>  $row */
    protected function relationship(array $row): NextOfKinRelationship
    {
        $relationship = $row['relationship'] ?? null;

        return match (true) {
            $relationship instanceof NextOfKinRelationship => $relationship,
            is_string($relationship) => NextOfKinRelationship::tryFrom($relationship)
                ?? NextOfKinRelationship::fromLabel($relationship),
            default => NextOfKinRelationship::Other,
        };
    }

    protected function nextMemberNumber(Cycle $cycle): int
    {
        return (int) $cycle->members()->max('member_number') + 1;
    }
}
