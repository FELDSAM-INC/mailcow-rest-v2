<?php

namespace Tests\Feature\Dkim;

use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T083 (US5 AS4, FR-022, FR-023): administrators copy a key (selector, public key and
 * private key) from a source with a key to one domain or alias domain; the source stays unchanged,
 * an existing target key needs `overwrite`, and an incomplete source is refused.
 */
class CopyDkimKeyTest extends TestCase
{
    use DkimTestHelpers;

    /** @var array{public: string, private: string} */
    private array $source;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('a.example');
        $f->domain('b.example');
        $f->aliasDomain('alias.example', 'b.example');
        $this->source = $f->dkimKey('a.example', 'shared', 2048);
    }

    public function test_the_key_is_copied_and_the_source_unchanged(): void
    {
        $this->asAdmin();

        $response = $this->send('POST', '/api/v2/dkim-keys/a.example/copy', ['target' => 'B.example'])->assertCreated()
            ->assertHeader('Location', '/api/v2/dkim-keys/b.example')
            ->assertJsonPath('data.name', 'b.example')
            ->assertJsonPath('data.key.selector', 'shared')
            ->assertJsonPath('data.dnsPublication.status', 'notConfigured');

        $this->assertNoPemInResponse($response);
        $this->assertDkimStored('b.example', 'shared', $this->source['public'], $this->source['private']);
        $this->assertDkimStored('a.example', 'shared', $this->source['public'], $this->source['private']);

        $record = $this->onlyAudit('dkimKeyCopy');
        $this->assertSame(['success', 'b.example', 'a.example'], [$record->outcome, $record->target_id, $record->details['sourceName']]);
        $this->assertSame('shared', $record->details['new']['selector']);
    }

    public function test_an_existing_target_key_needs_overwrite_and_its_old_private_key_goes(): void
    {
        $this->mailcow()->dkimKey('b.example', 'old', 1024);
        $this->asAdmin();
        $sourceTag = (string) $this->getJson('/api/v2/dkim-keys/a.example')->headers->get('ETag');
        $targetTag = (string) $this->getJson('/api/v2/dkim-keys/b.example')->headers->get('ETag');

        $this->send('POST', '/api/v2/dkim-keys/a.example/copy', ['target' => 'b.example'])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/dkim-key-exists');
        // If-Match applies to the target's key, not the source's.
        $this->send('POST', '/api/v2/dkim-keys/a.example/copy', ['target' => 'b.example', 'overwrite' => true], ['If-Match' => $sourceTag])->assertStatus(412);

        $this->send('POST', '/api/v2/dkim-keys/a.example/copy', ['target' => 'b.example', 'overwrite' => true], ['If-Match' => $targetTag])->assertOk()
            ->assertJsonPath('data.key.selector', 'shared');

        $this->assertNoPrivateKeyField('old.b.example');
        $this->assertDkimStored('b.example', 'shared', $this->source['public'], $this->source['private']);
        $this->assertSame(['conflict', 'versionOutdated', null], array_column($this->auditOfType('dkimKeyCopy'), 'failure_reason'));
    }

    public function test_invalid_targets_are_refused(): void
    {
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys/a.example/copy', ['target' => 'A.EXAMPLE'])->assertStatus(422)
            ->assertJsonPath('errors.target.0.code', 'sameAsSource');
        $this->send('POST', '/api/v2/dkim-keys/a.example/copy', ['target' => 'nothing.example'])->assertStatus(422)
            ->assertJsonPath('errors.target.0.code', 'notFound');
        $this->send('POST', '/api/v2/dkim-keys/a.example/copy', ['target' => 'a..b'])->assertStatus(422)
            ->assertJsonPath('errors.target.0.code', 'notFound');

        $this->assertNoDkim('nothing.example');
        $this->assertSame(['validationFailed', 'validationFailed', 'validationFailed'], array_column($this->auditOfType('dkimKeyCopy'), 'failure_reason'));
        $this->assertSame('nothing.example', $this->auditOfType('dkimKeyCopy')[1]->target_id);
    }

    public function test_a_source_without_a_key_is_not_found_and_an_alias_domain_can_be_the_target(): void
    {
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys/b.example/copy', ['target' => 'alias.example'])->assertNotFound();
        $this->send('POST', '/api/v2/dkim-keys/a.example/copy', ['target' => 'alias.example'])->assertCreated();

        $this->assertDkimStored('alias.example', 'shared', $this->source['public'], $this->source['private']);
    }

    public function test_an_unused_source_is_reached_by_its_exact_name(): void
    {
        $legacy = $this->mailcow()->legacyDkimKey('Old.EXAMPLE', 'legacy', 1024);
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys/Old.EXAMPLE/copy', ['target' => 'b.example'])->assertCreated();

        $this->assertDkimStored('b.example', 'legacy', $legacy['public'], $legacy['private']);
        $this->assertSame('Old.EXAMPLE', $this->onlyAudit('dkimKeyCopy')->details['sourceName']);
    }

    public function test_an_incomplete_source_is_a_conflict_naming_the_missing_parts(): void
    {
        $this->mailcow()->domain('half.example');
        $this->mailcow()->partialDkimKey('half.example', ['publicKey' => DkimFixtures::RSA_1024_PUBLIC, 'selector' => 'half']);
        $this->asAdmin();

        $response = $this->send('POST', '/api/v2/dkim-keys/half.example/copy', ['target' => 'b.example'])->assertStatus(409)
            ->assertJsonPath('type', 'about:blank');

        $this->assertStringContainsString('privateKey', (string) $response->json('detail'));
        $this->assertNoDkim('b.example');
        $this->assertSame(['conflict', 'b.example'], [$this->onlyAudit('dkimKeyCopy')->failure_reason, $this->onlyAudit('dkimKeyCopy')->target_id]);
    }

    public function test_a_domain_administrator_is_refused(): void
    {
        $this->asDomainAdmin(['a.example', 'b.example']);

        $this->send('POST', '/api/v2/dkim-keys/a.example/copy', ['target' => 'b.example'])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->assertNoDkim('b.example');
    }
}
