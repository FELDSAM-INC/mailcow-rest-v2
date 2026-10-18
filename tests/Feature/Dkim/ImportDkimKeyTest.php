<?php

namespace Tests\Feature\Dkim;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T082 (US5, FR-017 to FR-021, FR-027): administrators import an unencrypted RSA private
 * key; the public key is derived as legacy derives it, the normalised text is stored, an existing
 * key needs `overwrite`, and the key text never appears in a response, audit record or log line.
 */
class ImportDkimKeyTest extends TestCase
{
    use DkimTestHelpers;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
    }

    private static function legacyPem(): string
    {
        return (string) file_get_contents(__DIR__.'/../../fixtures/legacy/dkim/generated-2048.pem');
    }

    private static function legacyPublicKey(): string
    {
        return trim((string) file_get_contents(__DIR__.'/../../fixtures/legacy/dkim/generated-2048.pub'));
    }

    public function test_a_key_is_stored_with_the_public_key_legacy_derives(): void
    {
        $this->asAdmin();

        $response = $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => str_replace("\n", "\r\n", self::legacyPem())."\r\n", 'selector' => 'mx2024'])->assertCreated()
            ->assertHeader('Location', '/api/v2/dkim-keys/example.org')
            ->assertJsonPath('data.state', 'valid')
            ->assertJsonPath('data.key.selector', 'mx2024')
            ->assertJsonPath('data.key.keyLength', 2048)
            ->assertJsonPath('data.dnsPublication.status', 'notConfigured');

        $response->assertHeader('ETag', (string) $response->json('data.etag'));
        $this->assertNoPemInResponse($response);
        $this->assertDkimStored('example.org', 'mx2024', self::legacyPublicKey(), trim(self::legacyPem()));

        $record = $this->onlyAudit('dkimKeyImport');
        $this->assertSame(['success', 'example.org', false], [$record->outcome, $record->target_id, $record->details['overwriteRequested']]);
        $this->assertSame(['selector' => 'mx2024', 'keyLength' => 2048, 'publicKeySha256' => hash('sha256', (string) base64_decode(self::legacyPublicKey(), true))], $record->details['new']);
        $this->assertSame('notConfigured', $record->details['dnsPublication']['status']);
        $this->assertArrayNotHasKey('trigger', $record->details);
    }

    public function test_an_existing_key_needs_overwrite_and_the_old_private_key_goes(): void
    {
        $old = $this->mailcow()->dkimKey('example.org', 'old', 1024);
        $this->asAdmin();
        $tag = (string) $this->getJson('/api/v2/dkim-keys/example.org')->headers->get('ETag');

        $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => DkimFixtures::RSA_2048_PRIVATE])->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/dkim-key-exists');
        $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => DkimFixtures::RSA_2048_PRIVATE, 'overwrite' => true], ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->assertDkimStored('example.org', 'old', $old['public'], $old['private']);

        $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => DkimFixtures::RSA_2048_PRIVATE, 'overwrite' => true], ['If-Match' => $tag])->assertOk()
            ->assertJsonPath('data.key.selector', 'dkim')
            ->assertJsonPath('data.key.keyLength', 2048);

        $this->assertDkimStored('example.org', 'dkim', DkimFixtures::RSA_2048_PUBLIC, DkimFixtures::RSA_2048_PRIVATE);
        $this->assertNoPrivateKeyField('old.example.org');
        $records = $this->auditOfType('dkimKeyImport');
        $this->assertSame(['conflict', 'versionOutdated', null], array_column($records, 'failure_reason'));
        $this->assertTrue($records[2]->details['overwriteRequested']);
        $this->assertSame(['old', 1024], [$records[2]->details['old']['selector'], $records[2]->details['old']['keyLength']]);
    }

    public function test_invalid_keys_are_refused_without_echoing_the_text(): void
    {
        $this->asAdmin();
        $cases = [
            [DkimFixtures::ENCRYPTED_PKCS8_PRIVATE, 'encryptedPrivateKey'],
            [DkimFixtures::EC_P256_PRIVATE, 'unsupportedKeyType'],
            [DkimFixtures::RSA_512_PRIVATE, 'keyLengthOutOfRange'],
            [DkimFixtures::CERTIFICATE, 'invalidPrivateKey'],
            [DkimFixtures::TWO_PRIVATE_KEYS, 'invalidPrivateKey'],
            ["just some text\nwith lines", 'invalidPrivateKey'],
        ];

        foreach ($cases as [$text, $code]) {
            $response = $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => $text])->assertStatus(422)
                ->assertJsonPath('errors.privateKey.0.code', $code);

            foreach (array_filter(explode("\n", $text), fn (string $l): bool => strlen($l) > 12) as $line) {
                $this->assertStringNotContainsString($line, (string) $response->baseResponse->getContent());
            }
        }

        $this->assertNoDkim('example.org');
        $audit = (string) DB::table('restv2_audit_events')->get()->toJson();
        $logs = implode("\n", $this->logged);

        foreach ($cases as [$text]) {
            foreach (array_filter(explode("\n", $text), fn (string $l): bool => strlen($l) > 12 && ! str_starts_with($l, '-----')) as $line) {
                $this->assertStringNotContainsString($line, $audit);
                $this->assertStringNotContainsString($line, $logs);
            }
        }
        $this->assertCount(count($cases), $this->auditOfType('dkimKeyImport'));
        $this->assertSame(['privateKey' => [['code' => 'encryptedPrivateKey', 'params' => []]]], $this->auditOfType('dkimKeyImport')[0]->details['errors']);
    }

    public function test_an_invalid_oversized_text_is_too_long(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => DkimFixtures::seventeenKibText()])->assertStatus(422)
            ->assertJsonPath('errors.privateKey.0.code', 'tooLong')
            ->assertJsonPath('errors.privateKey.0.params', ['max' => 16384, 'unit' => 'bytes']);
    }

    public function test_a_name_that_is_not_a_domain_gets_an_unused_key_that_a_later_domain_reuses(): void
    {
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys/Future.EXAMPLE/import', ['privateKey' => DkimFixtures::RSA_1024_PRIVATE])->assertCreated()
            ->assertHeader('Location', '/api/v2/dkim-keys/future.example')
            ->assertJsonPath('data.state', 'unused')
            ->assertJsonPath('data.key.selector', 'dkim');

        $this->getJson('/api/v2/dkim-keys?state=unused')->assertJsonPath('data.0.name', 'future.example');

        $this->mailcow()->domain('future.example');
        $this->getJson('/api/v2/dkim-keys/future.example')->assertJsonPath('data.state', 'valid');
    }

    public function test_an_invalid_name_is_refused_on_the_path_parameter(): void
    {
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys/a..b/import', ['privateKey' => DkimFixtures::RSA_1024_PRIVATE])->assertStatus(422)
            ->assertJsonPath('errors.dkimName.0.code', 'invalidFormat')
            ->assertJsonPath('errors.dkimName.0.params.format', 'domainName');
    }

    public function test_domain_administrators_and_read_only_keys_are_refused(): void
    {
        $this->asDomainAdmin(['example.org']);
        $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => DkimFixtures::RSA_1024_PRIVATE])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->mailcow()->admin('admin', 'Passw0rd!');
        $key = $this->apiKey('admin', 'readOnly');
        $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => DkimFixtures::RSA_1024_PRIVATE], $this->keyHeader($key))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertNoDkim('example.org');
        $this->assertSame(['permissionDenied', 'permissionDenied'], array_column($this->auditOfType('dkimKeyImport'), 'failure_reason'));
    }

    public function test_an_unavailable_store_writes_nothing(): void
    {
        $this->asAdmin();
        $this->storeUnavailable();

        $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => DkimFixtures::RSA_1024_PRIVATE])->assertStatus(503);

        $this->assertSame('temporaryFailure', $this->onlyAudit('dkimKeyImport')->failure_reason);
    }
}
