<?php

namespace App\Notifications;

use App\Domain\Notifications\Sms\SmsMessage;
use App\Models\Cycle;
use App\Models\CycleMonth;
use App\Models\Member;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The September lockdown: no new loans, and savings capped.
 *
 * Sent twice — a week before the lockdown month opens and again on its first day.
 * The first is the one that matters: a member who wanted a last loan has until the
 * end of the month before it, and after that the rule is absolute.
 */
class LoanLockdownNotice extends MemberNotification
{
    public function __construct(
        public Cycle $cycle,
        public CycleMonth $lockdownMonth,
        public bool $hasStarted,
    ) {}

    public function toMail(Member $notifiable): MailMessage
    {
        $month = $this->lockdownMonth->month->format('F Y');
        $cap = $this->money($this->cycle->lockdown_savings_cap_ngwee);

        $mail = (new MailMessage)
            ->subject($this->hasStarted
                ? "Loan lockdown has started ({$month})"
                : "Loan lockdown starts in {$month}")
            ->greeting("Hello {$notifiable->full_name},");

        $mail = $this->hasStarted
            ? $mail->line("The cycle has reached {$month}, so the lockdown is now in force.")
            : $mail->line("From {$month} the cycle enters lockdown.");

        return $mail
            ->line('**No new loans may be issued.** Loans already running continue on their schedule.')
            ->line("**Savings are capped at {$cap} a month**, so the group can settle its books.")
            ->line('Everything outstanding must be repaid by '
                .$this->cycle->final_repayment_date->format('j F Y').', which is when the share-out is worked out.')
            ->action('See my position', url('/my'));
    }

    public function toSms(Member $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'Unity Savings: %s %s lockdown — no new loans and savings capped at %s a month. All loans must clear by %s.',
            $this->lockdownMonth->month->format('F'),
            $this->hasStarted ? 'lockdown is in force:' : 'brings',
            $this->money($this->cycle->lockdown_savings_cap_ngwee),
            $this->cycle->final_repayment_date->format('j M Y'),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'cycle_id' => $this->cycle->id,
            'lockdown_month' => $this->lockdownMonth->month->toDateString(),
            'has_started' => $this->hasStarted,
            'savings_cap_ngwee' => $this->cycle->lockdown_savings_cap_ngwee->getMinorAmount()->toInt(),
            'final_repayment_date' => $this->cycle->final_repayment_date->toDateString(),
        ];
    }
}
