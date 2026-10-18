<?php

namespace Tests\Support\Mailcow;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\Support\WebAuthn\VirtualCredential;

/**
 * Test wrapper around Database\Factories\MailcowRows: the same rows, with test defaults and the
 * virtual WebAuthn credentials of tests/Support/WebAuthn.
 */
class MailcowFactory extends MailcowRows
{
    public const PASSWORD = 'Passw0rd!';

    /**
     * A domain, created only when it does not exist yet.
     */
    public function ensureDomain(string $name, int $active = 1): void
    {
        if (! DB::table('domain')->where('domain', $name)->exists()) {
            $this->domain($name, $active);
        }
    }

    /**
     * A mailbox together with its domain.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int|bool>  $userAcl
     */
    public function mailboxWithDomain(string $username, array $attributes = [], int $active = 1, int $domainActive = 1, array $userAcl = []): void
    {
        $this->ensureDomain(explode('@', $username, 2)[1], $domainActive);
        $this->mailbox($username, self::PASSWORD, 'BLF-CRYPT', $active, attributes: $attributes, userAcl: $userAcl);
    }

    /**
     * A field of one of mailcow's own Redis hashes (connection `mailcow`), for example
     * `DOMAIN_MAP`, `RL_VALUE` or the DKIM hashes.
     */
    public function redisHash(string $key, string $field, string $value): void
    {
        $client = Redis::connection('mailcow')->client();

        if (! $client instanceof \Redis) {
            throw new RuntimeException('The mailcow Redis connection does not use phpredis.');
        }

        $client->hSet($key, $field, $value);
    }

    public function tfaWebAuthnCredential(string $username, VirtualCredential $credential, string $keyId = 'Security key', int $active = 1): int
    {
        return $this->tfaWebAuthn($username, $credential->id, $credential->publicKeyPem, $keyId, $active);
    }

    public function fido2Credential(
        string $username,
        VirtualCredential $credential,
        ?string $friendlyName = null,
        int $active = 1,
        ?string $certificateSubject = null,
    ): void {
        $this->fido2(
            $username,
            $credential->id,
            $credential->publicKeyPem,
            $credential->rpId,
            $credential->aaguid,
            $friendlyName,
            $active,
            $certificateSubject,
        );
    }
}
