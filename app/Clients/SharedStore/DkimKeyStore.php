<?php

namespace App\Clients\SharedStore;

use App\Services\Dkim\DkimKeyMaterial;
use App\Services\Dkim\StoredDkimKey;
use App\Support\Http\ETag;
use Redis;

/**
 * The DKIM keys in mailcow's Redis hashes `DKIM_PUB_KEYS`, `DKIM_SELECTORS` and `DKIM_PRIV_KEYS`,
 * which Rspamd reads for signing (feature 010 research R4). Every change is one atomic run of
 * resources/redis/dkim-key-store.lua (`EVALSHA`, falling back to `EVAL` after `NOSCRIPT`) with a
 * compare-and-set on an internal fingerprint that is never returned or logged. Failures raise
 * SharedStoreUnavailable; nothing is written then.
 */
class DkimKeyStore
{
    public const PUB_KEYS = 'DKIM_PUB_KEYS';

    public const SELECTORS = 'DKIM_SELECTORS';

    public const PRIV_KEYS = 'DKIM_PRIV_KEYS';

    private ?string $script = null;

    public function __construct(private readonly SharedStore $store) {}

    /** The stored key of a name, or null when neither a public key nor a selector is stored. */
    public function read(string $name): ?StoredDkimKey
    {
        return $this->store->run(function (Redis $redis) use ($name): ?StoredDkimKey {
            $pub = $redis->hGet(self::PUB_KEYS, $name);
            $selector = $redis->hGet(self::SELECTORS, $name);
            $pub = is_string($pub) ? $pub : null;
            $selector = is_string($selector) ? $selector : null;

            if ($pub === null && $selector === null) {
                return null;
            }

            $priv = $selector === null ? false : $redis->hGet(self::PRIV_KEYS, $selector.'.'.$name);
            $priv = is_string($priv) ? $priv : null;

            return new StoredDkimKey(
                name: $name,
                selector: $selector,
                publicKey: $pub,
                hasPrivateKey: $priv !== null,
                privateKeyHmac: $priv === null ? null : hash_hmac('sha256', $priv, (string) config('app.key')),
                fingerprint: self::fingerprint($name, $selector, $pub, $priv),
            );
        });
    }

    /**
     * Whether a public key or a selector is stored for the exact name (FR-008), without reading the
     * private key.
     */
    public function hasKey(string $name): bool
    {
        return $this->store->run(fn (Redis $redis): bool => (bool) $redis->hExists(self::PUB_KEYS, $name) || (bool) $redis->hExists(self::SELECTORS, $name));
    }

    /**
     * The names that have a key: the fields of `DKIM_PUB_KEYS` and `DKIM_SELECTORS` (FR-008), as a
     * set (name => true), without reading any value.
     *
     * @return array<string, true>
     */
    public function keyNames(): array
    {
        return $this->store->run(function (Redis $redis): array {
            $names = [];

            foreach ([self::PUB_KEYS, self::SELECTORS] as $hash) {
                $fields = $redis->hKeys($hash);

                foreach (is_array($fields) ? $fields : [] as $field) {
                    $names[(string) $field] = true;
                }
            }

            return $names;
        });
    }

    /**
     * Every stored selector (name => selector).
     *
     * @return array<string, string>
     */
    public function selectors(): array
    {
        return $this->hashValues(self::SELECTORS);
    }

    /**
     * Every stored public key text (name => public key).
     *
     * @return array<string, string>
     */
    public function publicKeys(): array
    {
        return $this->hashValues(self::PUB_KEYS);
    }

    /**
     * Whether `DKIM_PRIV_KEYS[<selector>.<name>]` exists, for each given field, in one pipeline,
     * without reading the private keys.
     *
     * @param  list<string>  $fields  `<selector>.<name>`
     * @return array<string, bool>
     */
    public function privateKeysExist(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        return $this->store->run(function (Redis $redis) use ($fields): array {
            $pipeline = $redis->multi(Redis::PIPELINE);

            foreach ($fields as $field) {
                $pipeline->hExists(self::PRIV_KEYS, $field);
            }

            $answers = $pipeline->exec();

            if (! is_array($answers)) {
                throw new SharedStoreUnavailable('mailcow\'s shared store did not answer the pipeline.');
            }

            return array_combine($fields, array_map(fn (mixed $a): bool => (bool) $a, array_values($answers)));
        });
    }

    /**
     * The stored keys of several names (null for a name without a key), in three round trips.
     *
     * @param  list<string>  $names
     * @return array<string, StoredDkimKey|null>
     */
    public function readMany(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $pubs = $this->store->getFields(self::PUB_KEYS, $names);
        $selectors = $this->store->getFields(self::SELECTORS, $names);
        $privateFields = [];

        foreach ($names as $name) {
            if ($selectors[$name] !== null) {
                $privateFields[] = $selectors[$name].'.'.$name;
            }
        }

        $privs = $this->store->getFields(self::PRIV_KEYS, array_values(array_unique($privateFields)));
        $keys = [];

        foreach ($names as $name) {
            $pub = $pubs[$name];
            $selector = $selectors[$name];

            if ($pub === null && $selector === null) {
                $keys[$name] = null;

                continue;
            }

            $priv = $selector === null ? null : ($privs[$selector.'.'.$name] ?? null);
            $keys[$name] = new StoredDkimKey(
                name: $name,
                selector: $selector,
                publicKey: $pub,
                hasPrivateKey: $priv !== null,
                privateKeyHmac: $priv === null ? null : hash_hmac('sha256', $priv, (string) config('app.key')),
                fingerprint: self::fingerprint($name, $selector, $pub, $priv),
            );
        }

        return $keys;
    }

    /**
     * The private key text of a name's current selector, for the gated export only (FR-026).
     */
    public function privateKey(string $name, string $selector): ?string
    {
        return $this->store->getField(self::PRIV_KEYS, $selector.'.'.$name);
    }

    public function create(string $name, string $selector, DkimKeyMaterial $material): DkimStoreResult
    {
        return $this->run('create', $name, null, $selector, $material->publicKey, $material->privateKey);
    }

    public function replace(string $name, string $selector, DkimKeyMaterial $material, ?string $expectedFingerprint): DkimStoreResult
    {
        return $this->run('replace', $name, $expectedFingerprint, $selector, $material->publicKey, $material->privateKey);
    }

    public function delete(string $name, ?string $expectedFingerprint): DkimStoreResult
    {
        return $this->run('delete', $name, $expectedFingerprint);
    }

    /**
     * Puts the given fields back (null = absent), but only while the stored key is still the one
     * with the given fingerprint (the audit compensation of research R13).
     */
    public function restore(string $name, string $currentFingerprint, ?string $selector, ?string $publicKey, ?string $privateKey): DkimStoreResult
    {
        return $this->run('restore', $name, $currentFingerprint, $selector, $publicKey, $privateKey);
    }

    /**
     * The version tag of a stored key (FR-051): 001's ETag over `[name, selector, public key,
     * HMAC-SHA-256 of the private key keyed with the app key]`, absent values `null`.
     */
    public function versionTag(StoredDkimKey $key): string
    {
        return ETag::of([$key->name, $key->selector, $key->publicKey, $key->privateKeyHmac]);
    }

    /**
     * The fingerprint the script computes, read with the `fingerprint` mode. Used by the tests to
     * prove that PHP and Lua agree.
     *
     * @internal
     */
    public function scriptFingerprint(string $name): string
    {
        return (string) $this->evaluate(['fingerprint', $name, '', '', '', ''])[1];
    }

    /**
     * The internal fingerprint `sha1(name \0 selector \0 public key \0 private key)`.
     *
     * @internal never returned to clients or logged
     */
    public static function fingerprint(string $name, ?string $selector, ?string $publicKey, ?string $privateKey): string
    {
        return sha1($name."\0".($selector ?? '')."\0".($publicKey ?? '')."\0".($privateKey ?? ''));
    }

    private function run(string $mode, string $name, ?string $expected, ?string $selector = null, ?string $pub = null, ?string $priv = null): DkimStoreResult
    {
        $answer = $this->evaluate([$mode, $name, $expected ?? '', $selector ?? '', $pub ?? '', $priv ?? '']);
        $status = (string) ($answer[0] ?? '');

        return new DkimStoreResult(
            $status,
            isset($answer[1]) && $answer[1] !== '' ? (string) $answer[1] : null,
            isset($answer[2]) && $answer[2] !== '' ? (string) $answer[2] : null,
        );
    }

    /**
     * @param  list<string>  $arguments
     * @return list<mixed>
     */
    private function evaluate(array $arguments): array
    {
        $script = $this->script();
        $args = [self::PUB_KEYS, self::SELECTORS, self::PRIV_KEYS, ...$arguments];

        return $this->store->run(function (Redis $redis) use ($script, $args): array {
            $result = $redis->evalSha(sha1($script), $args, 3);

            if ($result === false && str_contains((string) $redis->getLastError(), 'NOSCRIPT')) {
                $redis->clearLastError();
                $result = $redis->eval($script, $args, 3);
            }

            if (! is_array($result)) {
                $error = (string) $redis->getLastError();
                $redis->clearLastError();

                throw new SharedStoreUnavailable('The DKIM key script failed'.($error === '' ? '.' : ': '.$error));
            }

            return array_values($result);
        });
    }

    /**
     * @return array<string, string>
     */
    private function hashValues(string $hash): array
    {
        return $this->store->run(function (Redis $redis) use ($hash): array {
            $values = $redis->hGetAll($hash);

            return is_array($values) ? array_map('strval', array_combine(array_map('strval', array_keys($values)), $values)) : [];
        });
    }

    private function script(): string
    {
        return $this->script ??= (string) file_get_contents(resource_path('redis/dkim-key-store.lua'));
    }
}
