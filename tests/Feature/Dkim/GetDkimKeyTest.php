<?php

namespace Tests\Feature\Dkim;

use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T035 (US1 AS2, FR-007, FR-011, FR-051, FR-058): the entry of one name with its
 * record, its exact key length and its version tag, which changes whenever legacy changes the key.
 */
class GetDkimKeyTest extends TestCase
{
    use DkimTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('xn--bcher-kva.example');
        $this->asAdmin();
    }

    public function test_a_2048_bit_key_with_its_record(): void
    {
        $this->mailcow()->dkimKey('example.org', 'dkim', 2048);

        $response = $this->getJson('/api/v2/dkim-keys/example.org')->assertOk();
        $value = 'v=DKIM1;k=rsa;t=s;s=email;p='.DkimFixtures::RSA_2048_PUBLIC;

        $response->assertJsonPath('data.name', 'example.org')
            ->assertJsonPath('data.displayName', 'example.org')
            ->assertJsonPath('data.kind', 'domain')
            ->assertJsonPath('data.targetDomain', null)
            ->assertJsonPath('data.state', 'valid')
            ->assertJsonPath('data.key.selector', 'dkim')
            ->assertJsonPath('data.key.keyLength', 2048)
            ->assertJsonPath('data.key.publicKey', DkimFixtures::RSA_2048_PUBLIC)
            ->assertJsonPath('data.key.record.name', 'dkim._domainkey.example.org')
            ->assertJsonPath('data.key.record.type', 'TXT')
            ->assertJsonPath('data.key.record.value', $value);

        $parts = (array) $response->json('data.key.record.valueParts');
        $this->assertGreaterThan(1, count($parts));
        $this->assertSame($value, implode('', $parts));

        foreach ($parts as $part) {
            $this->assertLessThanOrEqual(255, strlen($part));
        }

        $response->assertHeader('ETag', (string) $response->json('data.etag'));
        $this->assertNoPemInResponse($response);
    }

    public function test_a_change_made_by_legacy_changes_the_version_tag(): void
    {
        $this->mailcow()->dkimKey('example.org', 'dkim', 1024);
        $first = $this->getJson('/api/v2/dkim-keys/example.org')->headers->get('ETag');

        // Legacy rewrites only the private key (an import of the same public key's pair would).
        $this->mailcow()->redisHash('DKIM_PRIV_KEYS', 'dkim.example.org', DkimFixtures::RSA_1024_PKCS1_PRIVATE);
        $second = $this->getJson('/api/v2/dkim-keys/example.org')->headers->get('ETag');

        $this->mailcow()->redisHash('DKIM_SELECTORS', 'example.org', 'other');
        $third = $this->getJson('/api/v2/dkim-keys/example.org')->assertJsonPath('data.key.selector', 'other')->headers->get('ETag');

        $this->assertNotSame($first, $second);
        $this->assertNotSame($second, $third);
    }

    public function test_a_unicode_name_finds_its_ascii_entry(): void
    {
        $this->mailcow()->dkimKey('xn--bcher-kva.example');

        $this->getJson('/api/v2/dkim-keys/B%C3%9CCHER.example')->assertOk()
            ->assertJsonPath('data.name', 'xn--bcher-kva.example')
            ->assertJsonPath('data.displayName', 'bücher.example');
    }

    public function test_an_administrator_reaches_an_unused_key_by_its_exact_name(): void
    {
        $this->mailcow()->legacyDkimKey('Example.ORG', 'legacy');

        $this->getJson('/api/v2/dkim-keys/Example.ORG')->assertOk()
            ->assertJsonPath('data.name', 'Example.ORG')
            ->assertJsonPath('data.state', 'unused')
            ->assertJsonPath('data.kind', 'none')
            ->assertJsonPath('data.key.selector', 'legacy');
        // The normalised spelling is the domain itself, which has no key.
        $this->getJson('/api/v2/dkim-keys/EXAMPLE.org')->assertOk()->assertJsonPath('data.state', 'missing');
    }

    public function test_an_unknown_name_is_not_found(): void
    {
        $this->getJson('/api/v2/dkim-keys/nothing.example')->assertNotFound();
        $this->getJson('/api/v2/dkim-keys/a..b')->assertNotFound();
    }

    public function test_a_domain_without_a_key_is_missing_without_a_version_tag(): void
    {
        $response = $this->getJson('/api/v2/dkim-keys/example.org')->assertOk()
            ->assertJsonPath('data.state', 'missing')
            ->assertJsonPath('data.key', null)
            ->assertJsonPath('data.etag', null);

        $this->assertFalse($response->headers->has('ETag'));
    }

    public function test_an_unavailable_store_is_a_temporary_failure(): void
    {
        $this->storeUnavailable();

        $this->getJson('/api/v2/dkim-keys/example.org')->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
    }
}
