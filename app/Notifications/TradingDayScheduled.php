<?php

namespace App\Notifications;

use App\Domain\Notifications\Sms\SmsMessage;
use App\Models\CycleMonth;
use App\Models\Member;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Trading day, to the committee.
 *
 * The date is the month's `trading_concludes_on`, which is already weekend-adjusted
 * by CycleMonthPlanner — so when the 7th falls on a Saturday this lands on the
 * Monday the group actually meets rather than on the constitution's nominal date.
 */
class TradingDayScheduled extends MemberNotification
{
    /**
     * @param  array{declarations: int, expected_in_ngwee: int, members: int}  $summary
     */
    public function __construct(public CycleMonth $month, public array $summary) {}

    public function toMail(Member $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Trading day: {$this->month->trading_concludes_on->format('l j F Y')}")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("Today is the {$this->month->label()} trading day.")
            ->line($this->summary['declarations'].' of '.$this->summary['members']
                .' members declared, expecting '.$this->money($this->summary['expected_in_ngwee']).' in.')
            ->line('Open the console to mark what is received, then conclude the session — nothing is '
                .'posted to any ledger until it is concluded.')
            ->action('Open the trading console', url('/app/trading'));
    }

    public function toSms(Member $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'Unity Savings: %s trading day is today. %d declarations, %s expected in. Console: %s',
            $this->month->label(),
            $this->summary['declarations'],
            $this->money($this->summary['expected_in_ngwee']),
            url('/app/trading'),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'cycle_month_id' => $this->month->id,
            'month_label' => $this->month->label(),
            'trading_on' => $this->month->trading_concludes_on->toDateString(),
            ...$this->summary,
        ];
    }
}
