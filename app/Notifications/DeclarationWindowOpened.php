<?php

namespace App\Notifications;

use App\Domain\Notifications\Sms\SmsMessage;
use App\Models\CycleMonth;
use App\Models\Member;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Sent at 08:00 on the 1st: this month's declaration window is open.
 *
 * Carries the two figures a member needs before they can fill the form — the
 * minimum saving and, from the lockdown month on, the ceiling — so somebody
 * reading it on a phone in the field can decide their number without signing in.
 */
class DeclarationWindowOpened extends MemberNotification
{
    public function __construct(public CycleMonth $month) {}

    public function toMail(Member $notifiable): MailMessage
    {
        $cycle = $this->month->cycle;
        $cap = $cycle->savingsCapForMonth($this->month->sequence);

        $mail = (new MailMessage)
            ->subject("Declarations are open for {$this->month->label()}")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("Declarations for {$this->month->label()} are now open.")
            ->line('Tell the treasurer what you will save and what you will repay by the end of '
                .$this->month->declarations_close_at->format('l j F').'.')
            ->line('The minimum saving is '.$this->money($cycle->min_savings_ngwee)
                .', in steps of '.$this->money($cycle->savings_increment_ngwee).'.');

        if ($cap !== null) {
            $mail->line('This is a lockdown month, so savings are capped at '.$this->money($cap).'.');
        }

        return $mail
            ->action('Make my declaration', url('/my/declarations'))
            ->line('Trading day is '.$this->month->trading_concludes_on->format('l j F').'.');
    }

    public function toSms(Member $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'Unity Savings: declarations for %s are open until %s. Minimum %s. Declare at %s',
            $this->month->label(),
            $this->month->declarations_close_at->format('j M'),
            $this->money($this->month->cycle->min_savings_ngwee),
            url('/my/declarations'),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'cycle_month_id' => $this->month->id,
            'month_label' => $this->month->label(),
            'closes_at' => $this->month->declarations_close_at->toIso8601String(),
        ];
    }
}
