<?php

namespace App\Domain\Notifications\Sms;

/**
 * One text message, as the gateway will be handed it.
 *
 * Notifications build this rather than a raw string so the sender id and any
 * future per-message options have somewhere to live without changing every
 * toSms() in the application.
 */
class SmsMessage
{
    public function __construct(
        public string $content,
        public ?string $to = null,
        public ?string $from = null,
    ) {}

    public static function make(string $content): self
    {
        return new self($content);
    }

    public function to(string $number): self
    {
        $this->to = $number;

        return $this;
    }

    public function from(string $sender): self
    {
        $this->from = $sender;

        return $this;
    }

    /** Trims the message to the configured length, keeping whole words where it can. */
    public function truncated(int $maxLength): self
    {
        if (mb_strlen($this->content) <= $maxLength) {
            return $this;
        }

        $clone = clone $this;
        $clone->content = rtrim(mb_substr($this->content, 0, $maxLength - 1)).'…';

        return $clone;
    }
}
