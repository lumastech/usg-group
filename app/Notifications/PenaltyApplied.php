<?php

namespace App\Notifications;

use App\Domain\Notifications\Sms\SmsMessage;
use App\Enums\LoanTransactionType;
use App\Models\Loan;
use App\Models\LoanTransaction;
use App\Models\Member;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A penalty has just been charged against a member's loan.
 *
 * Sent immediately rather than on a schedule, because a penalty a member first sees
 * on a statement three weeks later is a penalty they will dispute. The arithmetic is
 * spelled out in full — the rate, the multiplier and the result — so the member can
 * check it against the constitution themselves instead of taking the figure on trust.
 */
class PenaltyApplied extends MemberNotification
{
    public function __construct(
        public Loan $loan,
        public LoanTransaction $transaction,
        public int $daysLate = 0,
    ) {}

    /** The penalty's arithmetic, in one line. */
    public function workingOut(): string
    {
        if ($this->transaction->type === LoanTransactionType::LatePenaltyDaily) {
            return sprintf(
                '%d day%s late × %s a day = %s',
                $this->daysLate,
                $this->daysLate === 1 ? '' : 's',
                $this->money($this->loan->cycle->late_transfer_penalty_per_day_ngwee),
                $this->money($this->transaction->amount_ngwee),
            );
        }

        $rate = $this->loan->cycle->missed_installment_penalty_bps / 100;
        $base = $this->transaction->getRawOriginal('balance_after_ngwee')
            - $this->transaction->getRawOriginal('amount_ngwee');

        return sprintf(
            '%s%% of %s outstanding = %s',
            rtrim(rtrim(number_format($rate, 2), '0'), '.'),
            $this->money($base),
            $this->money($this->transaction->amount_ngwee),
        );
    }

    public function toMail(Member $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->transaction->type->label().' of '.$this->money($this->transaction->amount_ngwee))
            ->greeting("Hello {$notifiable->full_name},")
            ->line('A '.mb_strtolower($this->transaction->type->label())
                .' has been charged against your loan.')
            ->line('**How it was worked out:** '.$this->workingOut())
            ->line('Your loan balance is now '.$this->money($this->transaction->balance_after_ngwee).'.')
            ->line($this->transaction->notes ?? '')
            ->action('See my loan', url('/my/loan'))
            ->line('If you believe this is wrong, raise it with the treasurer before the next trading day.');
    }

    public function toSms(Member $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'Unity Savings: %s of %s on your loan (%s). Balance now %s.',
            $this->transaction->type->label(),
            $this->money($this->transaction->amount_ngwee),
            $this->workingOut(),
            $this->money($this->transaction->balance_after_ngwee),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'loan_id' => $this->loan->id,
            'loan_transaction_id' => $this->transaction->id,
            'type' => $this->transaction->type->value,
            'amount_ngwee' => $this->transaction->getRawOriginal('amount_ngwee'),
            'balance_after_ngwee' => $this->transaction->getRawOriginal('balance_after_ngwee'),
            'days_late' => $this->daysLate,
            'working_out' => $this->workingOut(),
        ];
    }
}
