<?php

namespace App\Notifications;

use App\Domain\Notifications\Sms\SmsMessage;
use App\Models\CycleMonth;
use App\Models\Member;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Two days before trading, to members with an installment falling due.
 *
 * The amount is included because that is the whole point of the message: a member
 * who has to sign in to find out what they owe is a member who transfers the wrong
 * figure and picks up a K100-a-day penalty for the difference.
 */
class RepaymentDueSoon extends MemberNotification
{
    public function __construct(
        public CycleMonth $month,
        public int $amountDueNgwee,
    ) {}

    public function toMail(Member $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your loan repayment is due on '.$this->month->trading_concludes_on->format('j F'))
            ->greeting("Hello {$notifiable->full_name},")
            ->line('Your '.$this->month->label().' loan repayment of '
                .$this->money($this->amountDueNgwee).' is due on '
                .$this->month->trading_concludes_on->format('l j F').'.')
            ->line('A payment received after that date is charged '
                .$this->money($this->month->cycle->late_transfer_penalty_per_day_ngwee)
                .' for every day it is late, and a month that closes short is charged a further 10% '
                .'of everything still outstanding.')
            ->action('See my loan', url('/my/loan'));
    }

    public function toSms(Member $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'Unity Savings: %s due on %s for your loan repayment. Late payments cost %s a day.',
            $this->money($this->amountDueNgwee),
            $this->month->trading_concludes_on->format('j M'),
            $this->money($this->month->cycle->late_transfer_penalty_per_day_ngwee),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'cycle_month_id' => $this->month->id,
            'month_label' => $this->month->label(),
            'due_on' => $this->month->trading_concludes_on->toDateString(),
            'amount_due_ngwee' => $this->amountDueNgwee,
        ];
    }
}
