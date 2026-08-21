<?php

use App\Domain\Notifications\Sms\SmsGateway;
use App\Domain\Notifications\Sms\SmsMessage;
use App\Enums\NotificationChannel;
use App\Models\Cycle;
use App\Models\Member;
use App\Models\User;
use App\Notifications\MemberNotification;
use Illuminate\Notifications\Messages\MailMessage;

/** A throwaway notification so the channel is tested rather than any one message. */
class TestSmsNotification extends MemberNotification
{
    public function __construct(public string $body = 'Hello from the group.') {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->line($this->body);
    }

    public function toSms(object $notifiable): SmsMessage
    {
        return SmsMessage::make($this->body);
    }
}

/** Records what it was asked to send instead of sending it. */
class RecordingGateway implements SmsGateway
{
    /** @var array<int, SmsMessage> */
    public array $sent = [];

    public function send(SmsMessage $message): void
    {
        $this->sent[] = $message;
    }
}

beforeEach(function () {
    $this->gateway = new RecordingGateway;
    $this->app->instance(SmsGateway::class, $this->gateway);

    $this->cycle = Cycle::factory()->create();
});

it('delivers the text to the number on the member record', function () {
    $member = Member::factory()->for($this->cycle)->create([
        'phone' => '0977123456',
        'notification_channel' => NotificationChannel::Sms,
    ]);

    $member->notify(new TestSmsNotification);

    expect($this->gateway->sent)->toHaveCount(1)
        ->and($this->gateway->sent[0]->to)->toBe('0977123456')
        ->and($this->gateway->sent[0]->content)->toBe('Hello from the group.')
        ->and($this->gateway->sent[0]->from)->toBe(config('notifications.sms.from'));
});

it('sends nothing when the member has no number on record', function () {
    $member = Member::factory()->for($this->cycle)->create([
        'phone' => null,
        'notification_channel' => NotificationChannel::Sms,
    ]);

    $member->notify(new TestSmsNotification);

    expect($this->gateway->sent)->toBeEmpty();
});

it('truncates rather than silently sending a multi-part message', function () {
    config()->set('notifications.sms.max_length', 40);

    $member = Member::factory()->for($this->cycle)->create([
        'phone' => '0977123456',
        'notification_channel' => NotificationChannel::Sms,
    ]);

    $member->notify(new TestSmsNotification(str_repeat('a', 200)));

    expect(mb_strlen($this->gateway->sent[0]->content))->toBe(40)
        ->and($this->gateway->sent[0]->content)->toEndWith('…');
});

it('does not text a member who asked for email only', function () {
    $member = Member::factory()->for($this->cycle)->create([
        'phone' => '0977123456',
        'notification_channel' => NotificationChannel::Mail,
        'user_id' => User::factory(),
    ]);

    $member->notify(new TestSmsNotification);

    expect($this->gateway->sent)->toBeEmpty();
});
