<?php

namespace Tests\Support\Dkim;

use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assertions on mailcow's DKIM hashes (connection `mailcow`) and on responses (feature 010 T030).
 * Used in Tests\TestCase.
 */
trait DkimAssertions
{
    /**
     * The three fields of a key, exactly as legacy stores them.
     */
    protected function assertDkimStored(string $name, string $selector, string $publicKey, ?string $privateKey = null): void
    {
        $redis = Redis::connection('mailcow');

        $this->assertSame($publicKey, $redis->hget('DKIM_PUB_KEYS', $name), "DKIM_PUB_KEYS[{$name}]");
        $this->assertSame($selector, $redis->hget('DKIM_SELECTORS', $name), "DKIM_SELECTORS[{$name}]");

        if ($privateKey !== null) {
            $this->assertSame($privateKey, $redis->hget('DKIM_PRIV_KEYS', $selector.'.'.$name), "DKIM_PRIV_KEYS[{$selector}.{$name}]");
        } else {
            $this->assertNotFalse($redis->hget('DKIM_PRIV_KEYS', $selector.'.'.$name), "DKIM_PRIV_KEYS[{$selector}.{$name}] is missing.");
        }
    }

    /**
     * Neither a public key nor a selector is stored for the name.
     */
    protected function assertNoDkim(string $name): void
    {
        $redis = Redis::connection('mailcow');

        $this->assertFalse((bool) $redis->hexists('DKIM_PUB_KEYS', $name), "DKIM_PUB_KEYS[{$name}] exists.");
        $this->assertFalse((bool) $redis->hexists('DKIM_SELECTORS', $name), "DKIM_SELECTORS[{$name}] exists.");
    }

    /**
     * No private key is stored under `<selector>.<name>`.
     */
    protected function assertNoPrivateKeyField(string $field): void
    {
        $this->assertFalse((bool) Redis::connection('mailcow')->hexists('DKIM_PRIV_KEYS', $field), "DKIM_PRIV_KEYS[{$field}] exists.");
    }

    /**
     * The response carries no PEM private key armour.
     *
     * @param  TestResponse<Response>  $response
     */
    protected function assertNoPemInResponse(TestResponse $response): void
    {
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $response->baseResponse->getContent());
    }
}
