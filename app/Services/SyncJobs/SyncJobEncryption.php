<?php

namespace App\Services\SyncJobs;

/**
 * Transport encryption to the remote IMAP server (feature 008 research R3), backed by legacy's
 * stored `imapsync.enc1` value. The runner turns `TLS` into imapsync `--tls1` (STARTTLS), which is
 * why the contract calls it `starttls`.
 */
enum SyncJobEncryption: string
{
    case Ssl = 'SSL';
    case Starttls = 'TLS';
    case Plain = 'PLAIN';

    /**
     * @throws \ValueError for a value that is not `ssl`, `starttls` or `plain`
     */
    public static function fromApi(string $value): self
    {
        return match ($value) {
            'ssl' => self::Ssl,
            'starttls' => self::Starttls,
            'plain' => self::Plain,
            default => throw new \ValueError("\"{$value}\" is not a sync-job encryption."),
        };
    }

    public function toApi(): string
    {
        return match ($this) {
            self::Ssl => 'ssl',
            self::Starttls => 'starttls',
            self::Plain => 'plain',
        };
    }

    /**
     * The contract values (`SyncJobEncryption`), in their documented order.
     *
     * @return list<string>
     */
    public static function apiValues(): array
    {
        return array_map(fn (self $e): string => $e->toApi(), self::cases());
    }
}
