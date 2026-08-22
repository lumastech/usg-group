<?php

namespace App\Notifications;

use App\Domain\Notifications\Sms\SmsMessage;
use App\Models\Member;
use App\Models\PayoutDestination;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a member that where their money goes has changed.
 *
 * Sent whoever made the change, including the member themselves — a message the member
 * expects costs them three seconds, and it is the only way the one they do not expect
 * reaches them. The account is named but masked: enough to recognise, not enough to be
 * useful to somebody reading over a shoulder.
 */
class PayoutDestinationChangedNotice extends MemberNotification
{
    public function __construct(
        public PayoutDestination $destination,
        public Member $actor,
        public string $change,
    ) {}

    public function headline(): string
    {
        return match ($this->change) {
            'added' => 'A new payment destination was added to your account',
            'default' => 'Your payments will now go to a different account',
            'removed' => 'A payment destination was removed from your account',
            default => 'Your payment details changed',
        };
    }

    public function toMail(Member $notifiable): MailMessage
    {
        $byOtherHand = $this->actor->id !== $notifiable->id;

        return (new MailMessage)
            ->subject($this->headline())
            ->greeting("Hello {$notifiable->full_name},")
            ->line($this->headline().'.')
            ->line('**Account:** '.$this->destination->label())
            ->line('**Name on the account:** '.($this->destination->resolved_account_name ?? 'not checked'))
            ->when($byOtherHand, fn (MailMessage $mail): MailMessage => $mail->line(
                "This was done by {$this->actor->full_name} on your behalf."
            ))
            ->action('Check my payment details', url('/my/settings'))
            ->line('**If this was not you, tell the treasurer or the chairperson today.** '
                .'Share-out and loan money is sent to whichever account is set here.');
    }

    public function toSms(Member $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'Unity Savings: %s (%s). If this was not you, tell the treasurer today.',
            $this->headline(),
            $this->destination->label(),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'payout_destination_id' => $this->destination->id,
            'change' => $this->change,
            'label' => $this->destination->label(),
            'actor_member_id' => $this->actor->id,
        ];
    }
}
