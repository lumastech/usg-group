<?php

namespace App\Notifications;

use App\Domain\Notifications\Sms\SmsMessage;
use App\Models\CycleMonth;
use App\Models\Member;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

/**
 * The month's full reporting pack, to the committee.
 *
 * Only the four group sheets are attached; thirty member statements would bounce
 * off most mailboxes, and they are already in the pack directory for whoever needs
 * one. The manifest is quoted in the body so the treasurer can see at a glance
 * whether the build was complete.
 */
class StatementPackCompiled extends MemberNotification
{
    /**
     * @param  array{month_label: string, directory: string, disk: string, files: array<int, array{label: string, path: string, bytes: int}>, member_count: int}  $manifest
     */
    public function __construct(public CycleMonth $month, public array $manifest) {}

    public function toMail(Member $notifiable): MailMessage
    {
        $group = $this->groupSheets();

        $mail = (new MailMessage)
            ->subject("{$this->manifest['month_label']} statement pack")
            ->greeting("Hello {$notifiable->full_name},")
            ->line("The {$this->manifest['month_label']} trading session has been concluded and the "
                .'reporting pack has been built.')
            ->line(count($this->manifest['files']).' files, including a statement for each of the '
                .$this->manifest['member_count'].' members, are in '
                .$this->manifest['disk'].':'.$this->manifest['directory'].'.')
            ->line('The four group sheets are attached.');

        foreach ($group as $file) {
            if (Storage::disk($this->manifest['disk'])->exists($file['path'])) {
                $mail->attachData(
                    Storage::disk($this->manifest['disk'])->get($file['path']),
                    basename($file['path']),
                    ['mime' => 'application/pdf'],
                );
            }
        }

        return $mail->action('Open the reports hub', url('/app/reports'));
    }

    public function toSms(Member $notifiable): SmsMessage
    {
        return SmsMessage::make(sprintf(
            'Unity Savings: %s concluded. Statement pack built (%d files, %d members). %s',
            $this->manifest['month_label'],
            count($this->manifest['files']),
            $this->manifest['member_count'],
            url('/app/reports'),
        ));
    }

    /**
     * The sheets that describe the whole group, as opposed to one member.
     *
     * @return array<int, array{label: string, path: string, bytes: int}>
     */
    protected function groupSheets(): array
    {
        return array_values(array_filter(
            $this->manifest['files'],
            fn (array $file): bool => ! str_contains($file['path'], '/members/'),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(Member $notifiable): array
    {
        return [
            'cycle_month_id' => $this->month->id,
            'month_label' => $this->manifest['month_label'],
            'directory' => $this->manifest['directory'],
            'disk' => $this->manifest['disk'],
            'file_count' => count($this->manifest['files']),
            'member_count' => $this->manifest['member_count'],
        ];
    }
}
