<?php

namespace App\Notifications;

use App\Domain\Notifications\Sms\SmsMessage;
use App\Models\CycleMonth;
use App\Models\Member;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

/**
 * The member's own statement for a month that has just been concluded.
 *
 * Attached rather than linked: the group's whole point is that a member can hold
 * their own record, and several of them read email on a phone with no data left
 * for a portal round trip. The SMS version says the figures instead, since there
 * is nothing to attach to a text.
 */
class MemberStatementReady extends MemberNotification
{
    /**
     * @param  array{label: string, path: string, bytes: int}|null  $statement
     * @param  array{savings_ngwee: int, loan_balance_ngwee: int, net_value_ngwee: int}  $position
     */
    public function __construct(
        public CycleMonth $month,
        public ?array $statement,
        public array $position,
        public string $disk = 'local',
    ) {}

    public function toMail(Member $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Your {$this->month->label()} statement")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("The {$this->month->label()} trading session has been concluded and the month's "
                .'figures are now final.')
            ->line('**Savings to date:** '.$this->money($this->position['savings_ngwee']))
            ->line('**Loan outstanding:** '.$this->money($this->position['loan_balance_ngwee']))
            ->line('**Net value:** '.$this->money($this->position['net_value_ngwee']))
            ->action('See my statement', url('/my/savings'));

        if ($this->statement !== null && Storage::disk($this->disk)->exists($this->statement['path'])) {
            $mail->attachData(
                Storage::disk($this->disk)->get($this->statement['path']),
                basename($this->statement['path']),
                ['mime' => 'application/pdf'],
            );
        }

        return $mail->line('Check it against your own record and raise anything that does not agree '
            .'with the treasurer before the next trading day.');
    }

    public function toSms(Member $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'Unity Savings %s: savings %s, loan %s, net value %s. Full statement at %s',
            $this->month->label(),
            $this->money($this->position['savings_ngwee']),
            $this->money($this->position['loan_balance_ngwee']),
            $this->money($this->position['net_value_ngwee']),
            url('/my/savings'),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'cycle_month_id' => $this->month->id,
            'month_label' => $this->month->label(),
            'statement_path' => $this->statement['path'] ?? null,
            ...$this->position,
        ];
    }
}
