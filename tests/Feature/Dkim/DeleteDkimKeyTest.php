<?php

namespace Tests\Feature\Dkim;

use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T084 (US5 AS5, FR-024, FR-025): administrators delete the key of any name; the three
 * fields go together, a domain's entry shows `missing` and an unused key's entry disappears. The
 * publication outcome is in the audit record.
 */
class DeleteDkimKeyTest extends TestCase
{
    use DkimTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->dkimKey('example.org', 'dkim', 1024);
        $this->mailcow()->dkimKey('unused.example', 'old', 2048);
    }

    public function test_an_unused_key_disappears_and_a_domain_s_key_leaves_missing(): void
    {
        $this->asAdmin();

        $this->send('DELETE', '/api/v2/dkim-keys/unused.example')->assertNoContent();
        $this->assertSame('', (string) $this->send('DELETE', '/api/v2/dkim-keys/example.org')->assertNoContent()->baseResponse->getContent());

        $this->assertNoDkim('unused.example');
        $this->assertNoPrivateKeyField('old.unused.example');
        $this->assertNoDkim('example.org');
        $this->assertNoPrivateKeyField('dkim.example.org');
        $this->getJson('/api/v2/dkim-keys')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.state', 'missing');

        $records = $this->auditOfType('dkimKeyDeletion');
        $this->assertSame(['unused.example', 'example.org'], array_column($records, 'target_id'));
        $this->assertSame(['selector' => 'old', 'keyLength' => 2048, 'publicKeySha256' => $records[0]->details['old']['publicKeySha256']], $records[0]->details['old']);
        $this->assertSame(['status' => 'notConfigured', 'reasonCode' => null, 'detail' => null], $records[1]->details['dnsPublication']);
    }

    public function test_a_name_without_a_key_is_not_found_and_an_outdated_tag_is_refused(): void
    {
        $this->asAdmin();

        $this->send('DELETE', '/api/v2/dkim-keys/nothing.example')->assertNotFound();
        $this->send('DELETE', '/api/v2/dkim-keys/example.org', null, ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->assertDkimStored('example.org', 'dkim', (string) $this->dkimField('DKIM_PUB_KEYS', 'example.org'));

        $tag = (string) $this->getJson('/api/v2/dkim-keys/example.org')->headers->get('ETag');
        $this->send('DELETE', '/api/v2/dkim-keys/example.org', null, ['If-Match' => $tag])->assertNoContent();
        $this->assertSame(['notFound', 'versionOutdated', null], array_column($this->auditOfType('dkimKeyDeletion'), 'failure_reason'));
    }

    public function test_a_legacy_key_is_deleted_by_its_exact_name(): void
    {
        $this->mailcow()->legacyDkimKey('Example.ORG', 'legacy');
        $this->asAdmin();

        $this->send('DELETE', '/api/v2/dkim-keys/Example.ORG')->assertNoContent();

        $this->assertNoDkim('Example.ORG');
        $this->assertNoPrivateKeyField('legacy.Example.ORG');
        $this->assertNotNull($this->dkimField('DKIM_SELECTORS', 'example.org'), 'The domain key stays.');
        $this->assertSame('Example.ORG', $this->onlyAudit('dkimKeyDeletion')->target_id);
    }

    public function test_an_unavailable_store_deletes_nothing(): void
    {
        $this->asAdmin();
        $this->storeUnavailable();

        $this->send('DELETE', '/api/v2/dkim-keys/example.org')->assertStatus(503);

        $this->assertNotNull($this->dkimField('DKIM_SELECTORS', 'example.org'));
        $this->assertSame('temporaryFailure', $this->onlyAudit('dkimKeyDeletion')->failure_reason);
    }

    public function test_domain_administrators_and_read_only_keys_are_refused(): void
    {
        $this->asDomainAdmin(['example.org']);
        $this->send('DELETE', '/api/v2/dkim-keys/example.org')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->send('DELETE', '/api/v2/dkim-keys/example.org', null, $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertNotNull($this->dkimField('DKIM_SELECTORS', 'example.org'));
        $this->assertSame(['permissionDenied', 'permissionDenied'], array_column($this->auditOfType('dkimKeyDeletion'), 'failure_reason'));
    }
}
