<?php

namespace App\Clients\Lmtp;

/**
 * The outcome of one LMTP delivery (feature 013 research R18): `delivered` (a 2xx reply),
 * `temporaryFailure` (a 4xx reply, a connection failure or the deadline) or `permanentFailure` (a
 * 5xx reply), with the reply code (null without a reply) and its text, for example Dovecot's
 * over-quota refusal `552 5.2.2 Mailbox is full`.
 */
final readonly class LmtpResult
{
    public const DELIVERED = 'delivered';

    public const TEMPORARY_FAILURE = 'temporaryFailure';

    public const PERMANENT_FAILURE = 'permanentFailure';

    private function __construct(
        public string $status,
        public ?int $code,
        public string $text,
        public bool $connectionFailed = false,
    ) {}

    public static function delivered(int $code = 250, string $text = '2.0.0 Saved'): self
    {
        return new self(self::DELIVERED, $code, $text);
    }

    public static function temporaryFailure(?int $code, string $text): self
    {
        return new self(self::TEMPORARY_FAILURE, $code, $text);
    }

    /** No reply at all: refused or broken connection, or the deadline before the message ended. */
    public static function connectionFailure(string $text): self
    {
        return new self(self::TEMPORARY_FAILURE, null, $text, true);
    }

    public static function permanentFailure(int $code, string $text): self
    {
        return new self(self::PERMANENT_FAILURE, $code, $text);
    }

    public function isDelivered(): bool
    {
        return $this->status === self::DELIVERED;
    }

    public function isTemporary(): bool
    {
        return $this->status === self::TEMPORARY_FAILURE;
    }

    /** The reply as one line, `<code> <text>`, or the connection error. */
    public function reply(): string
    {
        return trim(($this->code === null ? '' : $this->code.' ').$this->text);
    }
}
