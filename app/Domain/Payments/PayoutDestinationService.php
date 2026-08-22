<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Lenco\LencoOperator;
use App\Enums\MobileMoneyOperator;
use App\Enums\PayoutDestinationType;
use App\Events\PayoutDestinationChanged;
use App\Exceptions\DomainRuleException;
use App\Models\Member;
use App\Models\PayoutDestination;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Where a member is paid, and the checks that stand between a change and the money.
 *
 * Changing a destination needs no ledger tampering at all, which makes it the most
 * valuable thing in this system to get at. Four things guard it: the provider is asked
 * whose account it is, the answer is compared to the member's name, the member is told
 * out of band that something changed, and a destination altered on the eve of a payout
 * cannot be paid to without a second committee signature.
 */
class PayoutDestinationService
{
    public function __construct(protected PaymentGateway $gateway) {}

    /**
     * Adds a bank account, having asked the bank whose it is.
     *
     * Nothing is saved if the account cannot be resolved: an unverifiable destination
     * that sits in the list looking like the others is worse than none at all.
     */
    public function addBankAccount(
        Member $member,
        string $bankId,
        string $accountNumber,
        Member $actor,
        bool $makeDefault = true,
    ): PayoutDestination {
        $resolved = $this->gateway->resolveBankAccount($accountNumber, $bankId);

        return $this->store($member, $actor, $makeDefault, [
            'type' => PayoutDestinationType::BankAccount,
            'bank_id' => $bankId,
            'bank_name' => $resolved->bankName,
            'account_number' => $resolved->accountNumber ?? $accountNumber,
        ], $resolved);
    }

    public function addMobileMoney(
        Member $member,
        string $phone,
        ?MobileMoneyOperator $operator,
        Member $actor,
        bool $makeDefault = true,
    ): PayoutDestination {
        $normalised = LencoOperator::normalisePhone($phone);

        if ($normalised === null) {
            throw DomainRuleException::make("\"{$phone}\" is not a Zambian mobile number.");
        }

        $operator ??= LencoOperator::forPhone($normalised);

        if ($operator === null) {
            throw DomainRuleException::make(
                "We cannot tell which network {$normalised} is on. Choose it and try again."
            );
        }

        $resolved = $this->gateway->resolveMobileMoney($normalised, $operator);

        return $this->store($member, $actor, $makeDefault, [
            'type' => PayoutDestinationType::MobileMoney,
            'phone' => $normalised,
            'operator' => $operator,
        ], $resolved);
    }

    /** Moves the default. Only one destination per member may hold it. */
    public function makeDefault(PayoutDestination $destination, Member $actor): PayoutDestination
    {
        if ($destination->disabled_at !== null) {
            throw DomainRuleException::make('A destination that has been removed cannot be made the default.');
        }

        return DB::transaction(function () use ($destination, $actor): PayoutDestination {
            PayoutDestination::query()
                ->where('member_id', $destination->member_id)
                ->whereKeyNot($destination->id)
                ->update(['is_default' => false]);

            $destination->forceFill(['is_default' => true])->save();

            $this->announce($destination, $actor, 'default');

            return $destination->refresh();
        });
    }

    /**
     * Takes a destination out of use.
     *
     * Kept rather than deleted: a payout paid to it last month still has to be
     * explainable next year.
     */
    public function disable(PayoutDestination $destination, Member $actor): PayoutDestination
    {
        return DB::transaction(function () use ($destination, $actor): PayoutDestination {
            $destination->forceFill(['disabled_at' => Carbon::now(), 'is_default' => false])->save();

            $this->promoteAnother($destination);
            $this->announce($destination, $actor, 'removed');

            return $destination->refresh();
        });
    }

    /**
     * A committee member saying, on the record, that the different name is fine.
     *
     * The member is not allowed to clear their own mismatch — the whole point of the
     * check is that somebody other than whoever typed the number has looked at it.
     */
    public function confirmName(PayoutDestination $destination, Member $actor): PayoutDestination
    {
        if ($actor->id === $destination->member_id) {
            throw DomainRuleException::make(
                'A different name on the account has to be confirmed by somebody on the committee, '
                    .'not by the member being paid.'
            );
        }

        $destination->forceFill([
            'name_match_confirmed_by_member_id' => $actor->id,
            'name_match_confirmed_at' => Carbon::now(),
        ])->save();

        return $destination->refresh();
    }

    /**
     * Throws unless money may be sent here right now.
     *
     * Returns quietly for a destination that is merely new — the cooling-off period is
     * not a refusal, it is a second signature, and whether that signature is present is
     * the caller's business to know.
     */
    public function assertPayable(PayoutDestination $destination): void
    {
        if ($destination->disabled_at !== null) {
            throw DomainRuleException::make('That destination has been removed.');
        }

        if (config('payments.transfers.require_verified_destination') && $destination->verified_at === null) {
            throw DomainRuleException::make(
                'That destination has never been checked with the provider, so nothing can be sent to it.'
            );
        }
    }

    /** Whether sending here needs a second committee signature today. */
    public function needsSecondSignature(PayoutDestination $destination): bool
    {
        return $destination->isWithinCoolingOff() || $destination->hasUnconfirmedNameMismatch();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function store(
        Member $member,
        Member $actor,
        bool $makeDefault,
        array $attributes,
        ResolvedAccount $resolved,
    ): PayoutDestination {
        $score = AccountNameMatcher::score($member->full_name, $resolved->accountName);

        return DB::transaction(function () use ($member, $actor, $makeDefault, $attributes, $resolved, $score): PayoutDestination {
            $destination = new PayoutDestination($attributes + [
                'member_id' => $member->id,
                'resolved_account_name' => $resolved->accountName,
                'name_match_score' => $score,
                'verified_at' => Carbon::now(),
                'created_by_member_id' => $actor->id,
                'is_default' => false,
                'disabled_at' => null,
            ]);

            try {
                $destination->save();
            } catch (UniqueConstraintViolationException) {
                /*
                 * The member already has this account. Re-verifying it is the sensible
                 * reading of the request, not an error to show them.
                 */
                $destination = PayoutDestination::query()
                    ->where('member_id', $member->id)
                    ->where('fingerprint', $destination->computeFingerprint())
                    ->firstOrFail();

                $destination->forceFill([
                    'resolved_account_name' => $resolved->accountName,
                    'name_match_score' => $score,
                    'name_match_confirmed_at' => null,
                    'name_match_confirmed_by_member_id' => null,
                    'verified_at' => Carbon::now(),
                    'disabled_at' => null,
                ])->save();
            }

            if ($makeDefault || $this->hasNoDefault($member)) {
                PayoutDestination::query()
                    ->where('member_id', $member->id)
                    ->whereKeyNot($destination->id)
                    ->update(['is_default' => false]);

                $destination->forceFill(['is_default' => true])->save();
            }

            $this->announce($destination, $actor, 'added');

            return $destination->refresh();
        });
    }

    protected function hasNoDefault(Member $member): bool
    {
        return ! PayoutDestination::query()
            ->where('member_id', $member->id)
            ->where('is_default', true)
            ->whereNull('disabled_at')
            ->exists();
    }

    /** Keeps a member with usable destinations from being left with no default. */
    protected function promoteAnother(PayoutDestination $removed): void
    {
        $next = PayoutDestination::query()
            ->where('member_id', $removed->member_id)
            ->whereKeyNot($removed->id)
            ->usable()
            ->orderByDesc('id')
            ->first();

        $next?->forceFill(['is_default' => true])->save();
    }

    /**
     * Tells the member their money's destination changed, on the contacts they had
     * before the change — so somebody whose account has been taken over still hears
     * about it.
     */
    protected function announce(PayoutDestination $destination, Member $actor, string $change): void
    {
        PayoutDestinationChanged::dispatch($destination, $actor, $change);
    }
}
