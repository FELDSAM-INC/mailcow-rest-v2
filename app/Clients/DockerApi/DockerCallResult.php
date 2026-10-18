<?php

namespace App\Clients\DockerApi;

/**
 * Outcome of a dockerapi call: `success`, `failed` (with dockerapi's message or the error), or
 * `unknown` when the call timed out and the action may or may not have happened.
 */
final readonly class DockerCallResult
{
    public const SUCCESS = 'success';

    public const FAILED = 'failed';

    public const UNKNOWN = 'unknown';

    private function __construct(public string $status, public ?string $message) {}

    public static function success(?string $message = null): self
    {
        return new self(self::SUCCESS, $message);
    }

    public static function failed(string $message): self
    {
        return new self(self::FAILED, $message);
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
}
