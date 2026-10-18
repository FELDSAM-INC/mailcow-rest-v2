<?php

namespace Tests\Feature\Dkim;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Services\Audit\AuditContext;
use App\Services\Dkim\DkimKeyService;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T096 (US8 AS1, FR-054, FR-055): every key change, export attempt and publication of
 * one name is in the audit log, filtered with the generic `targetType` and `targetId`, newest first,
 * and the name may be given in Unicode or upper case. Records never hold a private key.
 */
class DkimAuditTrailTest extends TestCase
{
    use DkimTestHelpers;

    private const NAME = 'xn--bcher-kva.example';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain(self::NAME);
        $this->mailcow()->domain('source.example');
        $this->mailcow()->dkimKey('source.example', 'shared', 1024);
    }

    public function test_an_import_with_overwrite_records_the_change(): void
    {
        $this->mailcow()->dkimKey(self::NAME, 'old', 1024);
        $this->asAdmin();

        $this->send('POST', '/api/v2/dkim-keys/'.self::NAME.'/import', ['privateKey' => DkimFixtures::RSA_2048_PRIVATE, 'selector' => 'new', 'overwrite' => true])->assertOk();

        $record = $this->onlyAudit('dkimKeyImport');
        $this->assertSame(['admin', 'admin', 'success', 'dkimKey', self::NAME, 'bücher.example'], [$record->actor_username, $record->actor_role, $record->outcome, $record->target_type, $record->target_id, $record->target_name]);
        $this->assertNotEmpty($record->client_address);
        $this->assertNotEmpty($record->occurred_at);
        $this->assertTrue($record->details['overwriteRequested']);
        $this->assertSame(['old', 1024], [$record->details['old']['selector'], $record->details['old']['keyLength']]);
        $this->assertSame(['new', 2048, hash('sha256', (string) base64_decode(DkimFixtures::RSA_2048_PUBLIC, true))], [$record->details['new']['selector'], $record->details['new']['keyLength'], $record->details['new']['publicKeySha256']]);
        $this->assertSame(64, strlen((string) $record->details['old']['publicKeySha256']));
        $this->assertSame('notConfigured', $record->details['dnsPublication']['status']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($record) ?: '');
    }

    public function test_the_history_of_one_name(): void
    {
        $admin = new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::Password);
        $this->app->make(DkimKeyService::class)->generateForNewName(self::NAME, 'dkim', 1024, new AuditContext($admin, 'domainCreation'));
        $this->asAdmin();
        $this->enablePublication();
        $this->ispconfig()->zone(self::NAME.'.', 10, 2);

        $this->send('POST', '/api/v2/dkim-keys', ['name' => self::NAME, 'keySize' => 1024, 'overwrite' => true])->assertOk();
        $this->send('POST', '/api/v2/dkim-keys/'.self::NAME.'/import', ['privateKey' => DkimFixtures::RSA_1024_PRIVATE, 'overwrite' => true])->assertOk();
        $this->send('POST', '/api/v2/dkim-keys/source.example/copy', ['target' => 'BÜCHER.example', 'overwrite' => true])->assertOk();
        $this->getJson('/api/v2/dkim-keys/'.self::NAME.'/private-key')->assertForbidden();
        $this->send('POST', '/api/v2/dkim-keys/'.self::NAME.'/dns-publication')->assertOk();
        $this->send('DELETE', '/api/v2/dkim-keys/'.self::NAME)->assertNoContent();

        $expected = [
            ['dkimKeyDeletion', 'success'],
            ['dkimDnsPublication', 'success'],
            ['dkimPrivateKeyExport', 'failure'],
            ['dkimKeyCopy', 'success'],
            ['dkimKeyImport', 'success'],
            ['dkimKeyGeneration', 'success'],
            ['dkimKeyGeneration', 'success'],
        ];

        foreach ([self::NAME, 'bücher.example', 'BÜCHER.EXAMPLE', 'XN--BCHER-KVA.example'] as $targetId) {
            $response = $this->getJson('/api/v2/audit-events?targetType=dkimKey&targetId='.rawurlencode($targetId))->assertOk();
            $this->assertSame($expected, array_map(fn (array $e): array => [$e['eventType'], $e['outcome']], (array) $response->json('data')), $targetId);
        }

        $events = (array) $response->json('data');
        $this->assertSame('/api/v2/problems/dkim-private-key-export-disabled', $events[2]['details']['problemType']);
        $this->assertSame('source.example', $events[3]['details']['sourceName']);
        $this->assertSame('domainCreation', $events[6]['details']['trigger']);
        $this->assertSame('api', $events[5]['details']['trigger']);
        $this->assertSame('removed', $events[0]['details']['dnsPublication']['status']);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $response->baseResponse->getContent());
    }

    public function test_an_exact_legacy_spelling_finds_its_records(): void
    {
        $this->mailcow()->legacyDkimKey('Legacy.EXAMPLE');
        $this->asAdmin();

        $this->send('DELETE', '/api/v2/dkim-keys/Legacy.EXAMPLE')->assertNoContent();

        $this->getJson('/api/v2/audit-events?targetType=dkimKey&targetId=Legacy.EXAMPLE')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.eventType', 'dkimKeyDeletion');
    }
}
