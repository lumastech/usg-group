<?php

namespace App\Notifications;

use App\Domain\Notifications\Sms\SmsMessage;
use App\Models\CycleMonth;
use App\Models\Member;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The morning of the 3rd, to the members who have not declared yet.
 *
 * Only non-submitters receive this. A member who declared on the 1st and is then
 * chased on the 3rd stops reading the reminders, and the reminder is the one
 * message the month's trading day depends on.
 */
class DeclarationReminder extends MemberNotification
{
    public function __construct(public CycleMonth $month) {}

    public function toMail(Member $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Last day to declare for {$this->month->label()}")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("We have not received your declaration for {$this->month->label()}.")
            ->line('The window closes at the end of today, '
                .$this->month->declarations_close_at->format('l j F').'.')
            ->line('A member who has not declared is not on the trading sheet, so nothing can be '
                .'received from them on '.$this->month->trading_concludes_on->format('j F').'.')
            ->action('Declare now', url('/my/declarations'));
    }

    public function toSms(Member $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'Unity Savings: your %s declaration is still outstanding and the window closes tonight. Declare at %s',
            $this->month->label(),
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
