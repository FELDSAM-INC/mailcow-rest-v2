<?php

namespace Tests\Unit\Dkim;

use App\Clients\SharedStore\DkimKeyStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Dkim\DkimKeyMaterial;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Dkim\DkimFixtures;
use Tests\TestCase;

/**
 * Feature 010 T009 (research R4): every DKIM key change is one atomic Lua script on mailcow's
 * Redis with a compare-and-set on an internal fingerprint; the public version tag is an ETag over
 * an HMAC of the private key.
 */
class DkimKeyStoreTest extends TestCase
{
    private function store(): DkimKeyStore
    {
        return $this->app->make(DkimKeyStore::class);
    }

    private function raw(): \Redis
    {
        $client = Redis::connection('mailcow')->client();
        $this->assertInstanceOf(\Redis::class, $client);

        return $client;
    }

    private function material(int $bits = 1024): DkimKeyMaterial
    {
        $pair = DkimFixtures::rsa()[$bits];

        return new DkimKeyMaterial($pair['public'], $pair['private']);
    }

    public function test_create_writes_the_three_fields(): void
    {
        $material = $this->material();

        $result = $this->store()->create('example.org', 'dkim', $material);

        $this->assertSame('ok', $result->status);
        $this->assertSame(['example.org' => $material->publicKey], $this->raw()->hGetAll('DKIM_PUB_KEYS'));
        $this->assertSame(['example.org' => 'dkim'], $this->raw()->hGetAll('DKIM_SELECTORS'));
        $this->assertSame(['dkim.example.org' => $material->privateKey], $this->raw()->hGetAll('DKIM_PRIV_KEYS'));
    }

    public function test_create_refuses_a_name_with_a_public_key_or_a_selector(): void
    {
        $this->raw()->hSet('DKIM_SELECTORS', 'a.example', 'old');
        $this->raw()->hSet('DKIM_PUB_KEYS', 'b.example', 'MIIB');

        $this->assertSame('exists', $this->store()->create('a.example', 'dkim', $this->material())->status);
        $this->assertSame('exists', $this->store()->create('b.example', 'dkim', $this->material())->status);
        $this->assertFalse($this->raw()->hGet('DKIM_PUB_KEYS', 'a.example'));
        $this->assertFalse($this->raw()->hGet('DKIM_SELECTORS', 'b.example'));
        $this->assertSame([], $this->raw()->hGetAll('DKIM_PRIV_KEYS'));
    }

    public function test_replace_removes_the_old_private_key_and_checks_the_fingerprint(): void
    {
        $old = $this->material(1024);
        $new = $this->material(2048);
        $this->store()->create('example.org', 'old', $old);
        $stored = $this->store()->read('example.org');
        $this->assertNotNull($stored);

        $this->assertSame('outdated', $this->store()->replace('example.org', 'new', $new, str_repeat('0', 40))->status);
        $this->assertSame('old', $this->raw()->hGet('DKIM_SELECTORS', 'example.org'));

        $result = $this->store()->replace('example.org', 'new', $new, $stored->fingerprint);

        $this->assertSame('ok', $result->status);
        $this->assertSame('old', $result->oldSelector);
        $this->assertSame($old->publicKey, $result->oldPublicKey);
        $this->assertSame(['new.example.org' => $new->privateKey], $this->raw()->hGetAll('DKIM_PRIV_KEYS'));
        $this->assertSame('new', $this->raw()->hGet('DKIM_SELECTORS', 'example.org'));
    }

    public function test_delete_removes_the_three_fields(): void
    {
        $this->assertSame('absent', $this->store()->delete('example.org', null)->status);

        $this->store()->create('example.org', 'dkim', $this->material());
        $this->assertSame('outdated', $this->store()->delete('example.org', str_repeat('0', 40))->status);

        $result = $this->store()->delete('example.org', $this->store()->read('example.org')?->fingerprint);

        $this->assertSame('ok', $result->status);
        $this->assertSame('dkim', $result->oldSelector);
        $this->assertSame([], $this->raw()->hGetAll('DKIM_PUB_KEYS'));
        $this->assertSame([], $this->raw()->hGetAll('DKIM_SELECTORS'));
        $this->assertSame([], $this->raw()->hGetAll('DKIM_PRIV_KEYS'));
    }

    public function test_restore_puts_the_previous_key_back_only_while_unchanged(): void
    {
        $old = $this->material(1024);
        $new = $this->material(2048);
        $this->store()->create('example.org', 'old', $old);
        $this->store()->replace('example.org', 'new', $new, null);
        $written = $this->store()->read('example.org')?->fingerprint;
        $this->assertNotNull($written);

        $this->assertSame('outdated', $this->store()->restore('example.org', str_repeat('1', 40), 'old', $old->publicKey, $old->privateKey)->status);
        $this->assertSame('new', $this->raw()->hGet('DKIM_SELECTORS', 'example.org'));

        $this->assertSame('ok', $this->store()->restore('example.org', $written, 'old', $old->publicKey, $old->privateKey)->status);
        $this->assertSame('old', $this->raw()->hGet('DKIM_SELECTORS', 'example.org'));
        $this->assertSame($old->publicKey, $this->raw()->hGet('DKIM_PUB_KEYS', 'example.org'));
        $this->assertSame(['old.example.org' => $old->privateKey], $this->raw()->hGetAll('DKIM_PRIV_KEYS'));

        // Restoring "no key" removes the key written in between.
        $this->store()->restore('example.org', (string) $this->store()->read('example.org')?->fingerprint, null, null, null);
        $this->assertNull($this->store()->read('example.org'));
    }

    public function test_the_php_fingerprint_equals_the_scripts(): void
    {
        $cases = [
            'complete.example' => ['sel', 'PUB', 'PRIV'],
            'nopriv.example' => ['sel', 'PUB', null],
            'noselector.example' => [null, 'PUB', null],
            'onlyselector.example' => ['sel', null, null],
            'Bücher.Example' => ['sel', 'PUB', 'PRIV'],
        ];

        foreach ($cases as $name => [$selector, $pub, $priv]) {
            $selector === null ?: $this->raw()->hSet('DKIM_SELECTORS', $name, $selector);
            $pub === null ?: $this->raw()->hSet('DKIM_PUB_KEYS', $name, $pub);
            $priv === null ?: $this->raw()->hSet('DKIM_PRIV_KEYS', $selector.'.'.$name, $priv);

            $this->assertSame(DkimKeyStore::fingerprint($name, $selector, $pub, $priv), $this->store()->scriptFingerprint($name), $name);
            $this->assertSame(DkimKeyStore::fingerprint($name, $selector, $pub, $priv), $this->store()->read($name)?->fingerprint, $name);
        }
    }

    public function test_the_version_tag_covers_the_private_key_only_as_an_hmac(): void
    {
        $material = $this->material();
        $this->store()->create('example.org', 'dkim', $material);
        $key = $this->store()->read('example.org');
        $this->assertNotNull($key);
        $tag = $this->store()->versionTag($key);

        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]+"$/', $tag);
        $this->assertStringNotContainsString(sha1($material->privateKey), $tag);
        $this->assertStringNotContainsString(hash('sha256', $material->privateKey), $tag);

        $this->raw()->hSet('DKIM_PRIV_KEYS', 'dkim.example.org', $material->privateKey."\n");
        $changed = $this->store()->read('example.org');
        $this->assertNotNull($changed);
        $this->assertNotSame($tag, $this->store()->versionTag($changed));

        $before = $this->store()->versionTag($changed);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $reread = $this->store()->read('example.org');
        $this->assertNotNull($reread);
        $this->assertNotSame($before, $this->store()->versionTag($reread));
    }

    public function test_the_script_is_loaded_again_after_a_script_flush(): void
    {
        $this->store()->create('a.example', 'dkim', $this->material());
        $this->raw()->script('flush');

        $this->assertSame('ok', $this->store()->create('b.example', 'dkim', $this->material())->status);
        $this->assertSame('dkim', $this->raw()->hGet('DKIM_SELECTORS', 'b.example'));
    }

    public function test_an_unreachable_store_raises_unavailable_and_writes_nothing(): void
    {
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $this->expectException(SharedStoreUnavailable::class);
        $this->store()->create('example.org', 'dkim', $this->material());
    }
}
