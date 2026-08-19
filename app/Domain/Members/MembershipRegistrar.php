<?php

namespace App\Domain\Members;

use App\Enums\MemberStatus;
use App\Exceptions\RegistrationClosedException;
use App\Models\Cycle;
use App\Models\Member;
use Brick\Money\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Registers members into a cycle, enforcing the constitution's joining rules.
 *
 * Membership closes after the third month of the cycle, and anyone joining in the
 * third month pays the late registration fee instead of the standard joining fee.
 */
class MembershipRegistrar
{
    public const LATE_REGISTRATION_MONTH = 3;

    /**
     * @param  array<string, mixed>  $attributes
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

        return Member::create($attributes + [
            'cycle_id' => $cycle->id,
            'member_number' => $this->nextMemberNumber($cycle),
            'joined_on' => $joinedOn,
            'joining_month_sequence' => $sequence,
            'joining_fee_ngwee' => $this->joiningFeeFor($cycle, $sequence),
            'status' => MemberStatus::Active,
        ]);
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

    protected function nextMemberNumber(Cycle $cycle): int
    {
        return (int) $cycle->members()->max('member_number') + 1;
    }
}
