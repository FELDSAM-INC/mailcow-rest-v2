<?php

namespace Tests\Support\Mailcow;

use App\Services\Dkim\DkimKeyGenerator;
use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\Support\Dkim\DkimFixtures;
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
    public function mailboxWithDomain(string $username, array $attributes = [], int $active = 1, int $domainActive = 1, array $userAcl = [], string $kind = '', ?string $created = null): void
    {
        $this->ensureDomain(explode('@', $username, 2)[1], $domainActive);
        $this->mailbox($username, self::PASSWORD, 'BLF-CRYPT', $active, $kind, attributes: $attributes, userAcl: $userAcl, created: $created);
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

    /**
     * Feature 013: an Rspamd settings rule as legacy rsettings('add') inserts it (content as given,
     * `created` by the database). Returns its ID.
     */
    public function settingsRule(string $description, string $content, int $active = 0): int
    {
        return (int) DB::table('settingsmap')->insertGetId(['desc' => $description, 'content' => $content, 'active' => $active]);
    }

    /**
     * Feature 013: the server's GUID as dovecot-mailcow's entrypoint stores it (`INVALID` when
     * mailcow marked it invalid).
     */
    public function guid(string $value): void
    {
        DB::statement('REPLACE INTO `versions` (`application`, `version`) VALUES (?, ?)', ['GUID', $value]);
    }

    /**
     * Feature 013: raw values of mailcow's own Redis keys (connection `mailcow`, no prefix), exactly
     * as legacy's functions write them, for parity fixtures. `null` deletes a key.
     *
     * @param  array<string, string|null>  $keys
     */
    public function redisLegacy(array $keys): void
    {
        $client = Redis::connection('mailcow')->client();

        if (! $client instanceof \Redis) {
            throw new RuntimeException('The mailcow Redis connection does not use phpredis.');
        }

        foreach ($keys as $key => $value) {
            $value === null ? $client->del($key) : $client->set($key, $value);
        }
    }

    /**
     * A DKIM key in legacy's format (feature 010 T029): a fixture key of the given length
     * (DkimFixtures), or the public key derived from the given PEM. Returns the key pair.
     *
     * @return array{public: string, private: string}
     */
    public function dkimKey(string $name, string $selector = 'dkim', int|string $bitsOrPem = 1024, bool $withPrivateKey = true): array
    {
        if (is_int($bitsOrPem)) {
            $pair = DkimFixtures::rsa()[$bitsOrPem] ?? throw new RuntimeException("No {$bitsOrPem}-bit DKIM fixture.");
        } else {
            $public = (new DkimKeyGenerator)->publicKeyOf($bitsOrPem) ?? throw new RuntimeException('The PEM is not a private key.');
            $pair = ['public' => $public, 'private' => $bitsOrPem];
        }

        $this->dkimKeyHashes($name, $selector, $pair['public'], $withPrivateKey ? $pair['private'] : null);

        return $pair;
    }

    /**
     * A key that legacy stored under a name as typed, for example `Example.ORG` or
     * `bücher.example`, which Rspamd never uses (feature 010 FR-006).
     *
     * @return array{public: string, private: string}
     */
    public function legacyDkimKey(string $rawName, string $selector = 'dkim', int|string $bitsOrPem = 1024): array
    {
        return $this->dkimKey($rawName, $selector, $bitsOrPem);
    }

    /**
     * An incomplete key, for example after a partial legacy write: only the given parts
     * (`publicKey`, `selector`, `privateKey` (stored under `<selector>.<name>`, the selector
     * defaulting to `dkim`)).
     *
     * @param  array{publicKey?: string, selector?: string, privateKey?: string}  $parts
     */
    public function partialDkimKey(string $name, array $parts): void
    {
        $redis = Redis::connection('mailcow');

        if (isset($parts['publicKey'])) {
            $redis->hset('DKIM_PUB_KEYS', $name, $parts['publicKey']);
        }

        if (isset($parts['selector'])) {
            $redis->hset('DKIM_SELECTORS', $name, $parts['selector']);
        }

        if (isset($parts['privateKey'])) {
            $redis->hset('DKIM_PRIV_KEYS', ($parts['selector'] ?? 'dkim').'.'.$name, $parts['privateKey']);
        }
    }

    /**
     * A forwarding host as legacy fwdhost('add') stores it (feature 009): the address (in any
     * spelling, also non-canonical) with its source in `WHITELISTED_FWD_HOST`, and `KEEP_SPAM` set
     * when the spam filter is disabled.
     */
    public function forwardingHost(string $address, string $source, bool $keepSpam = false): void
    {
        $this->redisHash('WHITELISTED_FWD_HOST', $address, $source);

        if ($keepSpam) {
            $this->redisHash('KEEP_SPAM', $address, '1');
        }
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
