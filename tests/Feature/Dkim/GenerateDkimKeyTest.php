<?php

namespace Tests\Feature\Dkim;

use App\Audit\AuditEventType;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Log;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T043 (US2, FR-013 to FR-016, FR-051, FR-052, FR-054): generation of a key for a
 * domain or alias domain, with atomic rotation by administrators, stored in legacy's format.
 */
class GenerateDkimKeyTest extends TestCase
{
    use DkimTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('inactive.example', 0);
        $f->domain('foreign.example');
        $f->domain('xn--bcher-kva.example');
        $f->aliasDomain('alias.example', 'example.org');
    }

    public function test_a_key_with_the_default_selector_and_size(): void
    {
        $this->asAdmin();

        $response = $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org'])->assertCreated()
            ->assertHeader('Location', '/api/v2/dkim-keys/example.org')
            ->assertJsonPath('data.state', 'valid')
            ->assertJsonPath('data.key.keyLength', 2048)
            ->assertJsonPath('data.key.selector', 'dkim')
            ->assertJsonPath('data.key.complete', true)
            ->assertJsonPath('data.dnsPublication', ['status' => 'notConfigured', 'recordName' => null, 'reason' => null]);

        $response->assertHeader('ETag', (string) $response->json('data.etag'));
        $this->assertNoPemInResponse($response);

        $public = (string) $this->dkimField('DKIM_PUB_KEYS', 'example.org');
        $private = (string) $this->dkimField('DKIM_PRIV_KEYS', 'dkim.example.org');
        $this->assertSame($public, $response->json('data.key.publicKey'));
        $this->assertSame('dkim', $this->dkimField('DKIM_SELECTORS', 'example.org'));
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $private);
        $this->assertSame(trim($private), $private);
        $this->assertDoesNotMatchRegularExpression('/\s/', $public);

        $record = $this->onlyAudit('dkimKeyGeneration');
        $this->assertSame(['success', 'dkimKey', 'example.org'], [$record->outcome, $record->target_type, $record->target_id]);
        $this->assertSame('api', $record->details['trigger']);
        $this->assertSame(2048, $record->details['keyLength']);
        $this->assertFalse($record->details['overwriteRequested']);
        $this->assertArrayNotHasKey('old', $record->details);
        $this->assertSame(hash('sha256', (string) base64_decode($public, true)), $record->details['new']['publicKeySha256']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($record->details) ?: '');
    }

    public function test_the_server_default_selector_and_a_chosen_size(): void
    {
        config(['restv2.dkim.default_selector' => 'feldhost']);
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024])->assertCreated()
            ->assertJsonPath('data.key.selector', 'feldhost')
            ->assertJsonPath('data.key.keyLength', 1024);
        $this->assertNotNull($this->dkimField('DKIM_PRIV_KEYS', 'feldhost.example.org'));
    }

    public function test_an_existing_key_is_a_conflict_unless_an_administrator_overwrites_it(): void
    {
        $old = $this->mailcow()->dkimKey('example.org', 'old', 1024);
        $this->asAdmin();
        $tag = $this->getJson('/api/v2/dkim-keys/example.org')->headers->get('ETag');

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/dkim-key-exists');
        $this->assertDkimStored('example.org', 'old', $old['public'], $old['private']);

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'selector' => 'new', 'keySize' => 1024, 'overwrite' => true], ['If-Match' => '"stale"'])->assertStatus(412);
        $this->assertDkimStored('example.org', 'old', $old['public'], $old['private']);

        $response = $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'selector' => 'new', 'keySize' => 1024, 'overwrite' => true], ['If-Match' => (string) $tag])->assertOk()
            ->assertJsonPath('data.key.selector', 'new');
        $this->assertFalse($response->headers->has('Location'));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
        $this->assertNotSame($tag, $response->json('data.etag'));
        $this->assertNoPrivateKeyField('old.example.org');
        $this->assertSame('new', $this->dkimField('DKIM_SELECTORS', 'example.org'));
        $this->assertNotSame($old['public'], $this->dkimField('DKIM_PUB_KEYS', 'example.org'));

        [$conflict, $outdated, $success] = $this->auditOfType('dkimKeyGeneration');
        $this->assertSame(['failure', 'conflict', '/api/v2/problems/dkim-key-exists'], [$conflict->outcome, $conflict->failure_reason, $conflict->details['problemType']]);
        $this->assertSame(['failure', 'versionOutdated'], [$outdated->outcome, $outdated->failure_reason]);
        $this->assertTrue($success->details['overwriteRequested']);
        $this->assertSame(['selector' => 'old', 'keyLength' => 1024, 'publicKeySha256' => hash('sha256', (string) base64_decode($old['public'], true))], $success->details['old']);
        $this->assertSame('new', $success->details['new']['selector']);
    }

    public function test_an_incomplete_key_counts_as_an_existing_key(): void
    {
        $this->mailcow()->partialDkimKey('example.org', ['selector' => 'half']);
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024])->assertStatus(409);

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024, 'overwrite' => true])->assertOk();
        $this->assertSame('dkim', $this->dkimField('DKIM_SELECTORS', 'example.org'));
    }

    public function test_overwrite_for_a_name_without_a_key_creates_it(): void
    {
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024, 'overwrite' => true])->assertCreated();
        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'alias.example', 'keySize' => 1024], ['If-Match' => '"x"'])->assertStatus(412);
        $this->assertNoDkim('alias.example');
    }

    public function test_invalid_selectors_and_key_sizes_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        foreach ([['a b', 'invalidFormat'], ['a/b', 'invalidFormat'], ['', 'required'], [str_repeat('a', 256), 'tooLong']] as [$selector, $code]) {
            $response = $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'selector' => $selector])->assertStatus(422)
                ->assertJsonPath('errors.selector.0.code', $code);

            if ($code === 'invalidFormat') {
                $response->assertJsonPath('errors.selector.0.params.format', 'dkimSelector');
            }
        }

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1536])->assertStatus(422)
            ->assertJsonPath('errors.keySize.0.code', 'notInList')
            ->assertJsonPath('errors.keySize.0.params.allowed', [1024, 2048, 3072, 4096]);
        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => '1024', 'overwrite' => 'yes'])->assertStatus(422)
            ->assertJsonPath('errors.keySize.0.code', 'invalidType')
            ->assertJsonPath('errors.overwrite.0.code', 'invalidType');
        $this->send('POST', '/api/v2/dkim-keys', [])->assertStatus(422)->assertJsonPath('errors.name.0.code', 'required');
        $this->assertNoDkim('example.org');
        $this->assertCount(4 + 3, $this->auditOfType('dkimKeyGeneration'));
        $this->assertSame('validationFailed', $this->auditOfType('dkimKeyGeneration')[0]->failure_reason);
    }

    public function test_a_name_that_is_neither_a_domain_nor_an_alias_domain_is_not_found(): void
    {
        $this->mailcow()->dkimKey('unused.example');
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'nothing.example', 'keySize' => 1024])->assertNotFound();
        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'unused.example', 'keySize' => 1024, 'overwrite' => true])->assertNotFound();
        $this->assertNoDkim('nothing.example');
        $this->assertSame(['notFound', 'notFound'], array_column($this->auditOfType('dkimKeyGeneration'), 'failure_reason'));
    }

    public function test_a_domain_administrator_generates_for_own_names_only_and_never_overwrites(): void
    {
        $this->mailcow()->dkimKey('example.org', 'kept', 1024);
        $this->asDomainAdmin(['example.org']);

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'alias.example', 'keySize' => 1024])->assertCreated();
        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'foreign.example', 'keySize' => 1024])->assertNotFound();
        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024, 'overwrite' => true])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->assertSame('kept', $this->dkimField('DKIM_SELECTORS', 'example.org'));
        $this->assertNoDkim('foreign.example');
        $this->assertSame([null, 'notFound', 'permissionDenied'], array_column($this->auditOfType('dkimKeyGeneration'), 'failure_reason'));
    }

    public function test_a_read_only_key_is_refused_and_audited(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('admin', 'readOnly');

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org'], $this->keyHeader($key))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $record = $this->onlyAudit('dkimKeyGeneration');
        $this->assertSame(['failure', 'permissionDenied', 'example.org'], [$record->outcome, $record->failure_reason, $record->target_id]);
    }

    public function test_names_are_stored_in_lower_case_ascii_and_inactive_domains_get_keys(): void
    {
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys', ['name' => ' BÜCHER.Example ', 'keySize' => 1024])->assertCreated()
            ->assertHeader('Location', '/api/v2/dkim-keys/xn--bcher-kva.example')
            ->assertJsonPath('data.name', 'xn--bcher-kva.example');
        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'inactive.example', 'keySize' => 1024])->assertCreated();

        $this->assertNotNull($this->dkimField('DKIM_SELECTORS', 'xn--bcher-kva.example'));
        $this->assertNoDkim('bücher.example');
    }

    public function test_an_unavailable_store_writes_nothing(): void
    {
        $this->asAdmin();
        $this->storeUnavailable();

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024])->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure');

        $this->assertNoDkim('example.org');
        $record = $this->onlyAudit('dkimKeyGeneration');
        $this->assertSame(['failure', 'temporaryFailure'], [$record->outcome, $record->failure_reason]);
    }

    public function test_a_failed_audit_record_restores_the_previous_key(): void
    {
        $old = $this->mailcow()->dkimKey('example.org', 'old', 1024);
        $this->asAdmin();
        $this->app->instance(AuditLogger::class, new FlakyAuditLogger(failingTypes: [AuditEventType::DkimKeyGeneration]));
        $log = Log::spy();

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024, 'overwrite' => true, 'selector' => 'new'])->assertStatus(500);
        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'alias.example', 'keySize' => 1024])->assertStatus(500);

        $this->assertDkimStored('example.org', 'old', $old['public'], $old['private']);
        $this->assertNoPrivateKeyField('new.example.org');
        $this->assertNoDkim('alias.example');
        $log->shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'example.org') && ! str_contains($message, 'PRIVATE KEY'));
        $log->shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'alias.example'));
    }

    public function test_the_contract_example_key_of_the_fixtures_is_not_reused(): void
    {
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024])->assertCreated();

        $this->assertNotSame(DkimFixtures::RSA_1024_PUBLIC, $this->dkimField('DKIM_PUB_KEYS', 'example.org'));
    }
}
