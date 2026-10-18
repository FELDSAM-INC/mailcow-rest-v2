<?php

namespace App\Services\Dkim;

/**
 * Settings of the FeldHost DKIM DNS publication (002 research D24, 010 research R11). The
 * publication counts as enabled only when RESTV2_DKIM_DNS_PUBLICATION=y and the URL, user and
 * password are all set. The password is hidden from debug output and serialisation.
 */
final class IspconfigSettings
{
    public function __construct(
        public readonly bool $flag,
        public readonly string $url,
        public readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        public readonly int $dnsServerId,
        public readonly bool $verifyTls,
        public readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            flag: (bool) config('restv2.dkim.dns_publication'),
            url: trim((string) config('restv2.ispconfig.url')),
            username: (string) config('restv2.ispconfig.username'),
            password: (string) config('restv2.ispconfig.password'),
            dnsServerId: (int) config('restv2.ispconfig.dns_server_id', 1),
            verifyTls: (bool) config('restv2.ispconfig.verify_tls', true),
            timeout: max(1, (int) config('restv2.ispconfig.timeout', 15)),
        );
    }

    public function enabled(): bool
    {
        return $this->flag && $this->url !== '' && $this->username !== '' && $this->password !== '';
    }

    /** Switched on but missing the URL, user or password. */
    public function incomplete(): bool
    {
        return $this->flag && ! $this->enabled();
    }

    public function password(): string
    {
        return $this->password;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'flag' => $this->flag,
            'url' => $this->url,
            'username' => $this->username,
            'password' => $this->password === '' ? '' : '***',
            'dnsServerId' => $this->dnsServerId,
            'verifyTls' => $this->verifyTls,
            'timeout' => $this->timeout,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return $this->__debugInfo();
    }
}
