<?php

namespace App\Domain\Notifications\Sms;

use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;

/**
 * The stand-in gateway: writes the message it would have sent to the log.
 *
 * This is the default binding and will stay so until the group has a provider
 * account. It exists so the whole notification path — preferences, channel
 * selection, copy, the scheduled run — is exercised end to end today, rather than
 * being written blind against an interface nothing calls.
 */
class LogSmsGateway implements SmsGateway
{
    public function __construct(protected LogManager $log) {}

    public function send(SmsMessage $message): void
    {
        $this->logger()->info('SMS (not sent — no gateway configured)', [
            'to' => $message->to,
            'from' => $message->from,
            'characters' => mb_strlen($message->content),
            'content' => $message->content,
        ]);
    }

    protected function logger(): LoggerInterface
    {
        $channel = config('notifications.sms.log_channel');

        return $channel === null ? $this->log->driver() : $this->log->channel($channel);
    }
}
