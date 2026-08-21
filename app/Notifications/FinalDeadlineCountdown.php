<?php

namespace App\Notifications;

use App\Domain\Notifications\Sms\SmsMessage;
use App\Models\Cycle;
use App\Models\Member;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Weekly from October, to every member still carrying a loan balance.
 *
 * Two numbers: what is outstanding, and what has to be paid on each remaining
 * trading day to clear it by the final repayment date. The second is the one that
 * changes behaviour — "you owe K4,000" reads as a problem for later, "K2,000 on
 * each of the next two trading days" reads as a decision for this week.
 */
class FinalDeadlineCountdown extends MemberNotification
{
    /**
     * @param  array<int, array{label: string, due_on: string}>  $remainingTradingDays
     */
    public function __construct(
        public Cycle $cycle,
        public int $balanceNgwee,
        public array $remainingTradingDays,
        public int $perTradingDayNgwee,
        public int $daysRemaining,
    ) {}

    public function toMail(Member $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->daysRemaining.' days to clear your loan')
            ->greeting("Hello {$notifiable->full_name},")
            ->line('Every loan in the cycle must be fully repaid by '
                .$this->cycle->final_repayment_date->format('l j F Y')
                .' — '.$this->daysRemaining.' days from today.')
            ->line('**You still owe '.$this->money($this->balanceNgwee).'.**');

        if ($this->remainingTradingDays === []) {
            $mail->line('There are no trading days left before the deadline. Arrange settlement with the '
                .'treasurer directly.');
        } else {
            $mail->line('There '.(count($this->remainingTradingDays) === 1 ? 'is' : 'are').' '
                .count($this->remainingTradingDays).' trading day'
                .(count($this->remainingTradingDays) === 1 ? '' : 's').' left ('
                .implode(', ', array_map(
                    fn (array $day): string => $day['label'],
                    $this->remainingTradingDays,
                )).'), so '.$this->money($this->perTradingDayNgwee).' on each of them clears it.');
        }

        return $mail
            ->line('A balance still outstanding on the deadline is treated as a default and is '
                .'recovered from your share-out.')
            ->action('See my loan', url('/my/loan'));
    }

    public function toSms(Member $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'Unity Savings: %s still outstanding, due in full by %s (%d days). %s',
            $this->money($this->balanceNgwee),
            $this->cycle->final_repayment_date->format('j M'),
            $this->daysRemaining,
            $this->remainingTradingDays === []
                ? 'No trading days left — contact the treasurer.'
                : sprintf('Pay %s on each of the %d remaining trading days.',
                    $this->money($this->perTradingDayNgwee),
                    count($this->remainingTradingDays)),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'cycle_id' => $this->cycle->id,
            'balance_ngwee' => $this->balanceNgwee,
            'per_trading_day_ngwee' => $this->perTradingDayNgwee,
            'remaining_trading_days' => $this->remainingTradingDays,
            'days_remaining' => $this->daysRemaining,
            'final_repayment_date' => $this->cycle->final_repayment_date->toDateString(),
        ];
    }
}
