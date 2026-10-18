<?php

namespace App\Clients\DockerApi;

/**
 * Outcome of a dockerapi call: `success` (with dockerapi's message and, for commands that answer
 * with JSON data such as `doveadm get_acl`, the decoded data), `failed` (with dockerapi's message or
 * the error; `unreachable` when the container service or the container could not be reached at
 * all), or `unknown` when the call timed out and the action may or may not have happened.
 */
final readonly class DockerCallResult
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    private function __construct(
        public string $status,
        public ?string $message,
        public bool $unreachable = false,
        public mixed $data = null,
    ) {}

    public static function success(?string $message = null, mixed $data = null): self
    {
        return new self(self::SUCCESS, $message, false, $data);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
    }

    /** A failure before the command reached the container: connection refused, no container. */
    public static function unreachable(string $message): self
    {
        return new self(self::FAILED, $message, true);
    }

    public static function unknown(string $message): self
    {
        return new self(self::UNKNOWN, $message);
    }

    public function isSuccess(): bool
    {
        return $this->status === self::SUCCESS;
    }

    public function isUnknown(): bool
    {
        return $this->status === self::UNKNOWN;
    }

    public function isUnreachable(): bool
    {
        return $this->status === self::FAILED && $this->unreachable;
    }
}
