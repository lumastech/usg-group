<?php

namespace App\Notifications;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Invites a member to activate the portal login linked to their record.
 *
 * Email is the only channel today. Most of the group reaches the portal by phone,
 * so `via()` is written to take an SMS channel alongside mail without the calling
 * code changing — the payload the SMS driver needs is already in `toArray()`.
 */
class MemberInvitation extends Notification
{
    use Queueable;

    public function __construct(public Member $member, public string $token) {}

    /**
     * Build the invitation with a fresh activation token.
     *
     * The token is a password reset token, so accepting the invitation and setting
     * a first password are the same flow, and it expires on the same schedule.
     */
    public static function for(Member $member): self
    {
        return new self($member, Password::broker()->createToken($member->user));
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('app.name').' login')
            ->greeting("Hello {$this->member->full_name},")
            ->line('A login has been created for you on the Unity Savings portal, where you can declare your savings, follow your balance and see your statement.')
            ->line("You are member number {$this->member->member_number} for the {$this->member->cycle->name} cycle.")
            ->action('Set your password', $this->activationUrl())
            ->line('If you were not expecting this, tell the treasurer and ignore this message.');
    }

    /**
     * The same details an SMS channel would send, kept in one place.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'member_id' => $this->member->id,
            'member_number' => $this->member->member_number,
            'full_name' => $this->member->full_name,
            'phone' => $this->member->phone,
            'activation_url' => $this->activationUrl(),
        ];
    }

    protected function activationUrl(): string
    {
        return route('password.reset', [
            'token' => $this->token,
            'email' => $this->member->user?->email,
        ]);
    }
}
