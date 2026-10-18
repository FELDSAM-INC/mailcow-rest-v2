<?php

namespace Tests\Feature\Dkim;

use App\Auth\Role;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Dkim\DkimFixtures;
use Tests\Support\Dkim\DkimTestHelpers;
use Tests\TestCase;

/**
 * Feature 010 T094 (US7, FR-028 to FR-031, SC-009): with the FeldHost publication enabled, every key
 * change updates the TXT record `<selector>._domainkey` of the ISPConfig zone whose origin is the
 * name, outcomes are reported (in the audit record for deletions), failures never undo the key
 * change, and administrators can publish a record again.
 */
class DkimDnsPublicationTest extends TestCase
{
    use DkimTestHelpers;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('b.example');
        $this->mailcow()->domain('sub.parent.example');
        $this->enablePublication();
        config(['restv2.ispconfig.dns_server_id' => 3]);
        $this->ispconfig()->zone('example.org.', 10, 7);
        $this->ispconfig()->zone('b.example.', 11, 7);
        $this->ispconfig()->zone('parent.example.', 12, 7);
        Event::listen(MessageLogged::class, function (MessageLogged $event): void {
            $this->logged[] = $event->message.' '.json_encode($event->context);
        });
        $this->asAdmin();
    }

    private static function value(string $public): string
    {
        return 'v=DKIM1;k=rsa;t=s;s=email;p='.$public;
    }

    public function test_a_generation_creates_the_record(): void
    {
        $response = $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'selector' => 'sel1', 'keySize' => 1024])->assertCreated()
            ->assertJsonPath('data.dnsPublication', ['status' => 'published', 'recordName' => 'sel1._domainkey', 'reason' => null]);

        $public = (string) $response->json('data.key.publicKey');
        $this->ispconfig()->assertRecord(10, 'sel1._domainkey', self::value($public));
        $add = $this->ispconfig()->lastCall('dns_txt_add');
        $this->assertSame(6, $add['clientId']);
        $this->assertSame(['server_id' => 3, 'zone' => 10, 'name' => 'sel1._domainkey', 'type' => 'TXT', 'data' => self::value($public), 'ttl' => 7200, 'active' => 'Y'], array_diff_key($add['params'], ['stamp' => 1]));
        $this->assertSame('published', $this->onlyAudit('dkimKeyGeneration')->details['dnsPublication']['status']);
    }

    public function test_imports_update_or_move_the_record_and_a_copy_publishes_for_its_target(): void
    {
        $this->ispconfig()->record(10, 'dkim._domainkey.example.org.', 'TXT', 'v=DKIM1;p=old');

        $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => DkimFixtures::RSA_1024_PRIVATE])->assertCreated()
            ->assertJsonPath('data.dnsPublication.status', 'published');
        // The fully qualified record was updated, not duplicated.
        $this->ispconfig()->assertRecord(10, 'dkim._domainkey', self::value(DkimFixtures::RSA_1024_PUBLIC));
        $this->ispconfig()->assertCallCount('dns_txt_add', 0);

        $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => DkimFixtures::RSA_2048_PRIVATE, 'overwrite' => true])->assertOk()
            ->assertJsonPath('data.dnsPublication.status', 'published');
        $this->ispconfig()->assertRecord(10, 'dkim._domainkey', self::value(DkimFixtures::RSA_2048_PUBLIC));

        $this->send('POST', '/api/v2/dkim-keys/example.org/import', ['privateKey' => DkimFixtures::RSA_2048_PRIVATE, 'selector' => 'new', 'overwrite' => true])->assertOk()
            ->assertJsonPath('data.dnsPublication.status', 'published');
        $this->ispconfig()->assertRecord(10, 'new._domainkey', self::value(DkimFixtures::RSA_2048_PUBLIC));
        $this->ispconfig()->assertCallCount('dns_txt_delete', 1);

        $this->send('POST', '/api/v2/dkim-keys/example.org/copy', ['target' => 'b.example'])->assertCreated()
            ->assertJsonPath('data.dnsPublication.status', 'published');
        $this->ispconfig()->assertRecord(11, 'new._domainkey', self::value(DkimFixtures::RSA_2048_PUBLIC));
        $this->assertSame('published', $this->onlyAudit('dkimKeyCopy')->details['dnsPublication']['status']);
    }

    public function test_the_old_selector_s_record_is_removed(): void
    {
        $this->mailcow()->dkimKey('example.org', 'old', 1024);
        $this->ispconfig()->record(10, 'old._domainkey', 'TXT', self::value(DkimFixtures::RSA_1024_PUBLIC));

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'selector' => 'fresh', 'keySize' => 1024, 'overwrite' => true])->assertOk()
            ->assertJsonPath('data.dnsPublication.status', 'published');

        $this->ispconfig()->assertCallCount('dns_txt_delete', 1);
        $this->ispconfig()->assertCallCount('dns_txt_add', 1);
    }

    public function test_no_zone_and_a_parent_zone_only_are_reported(): void
    {
        $this->mailcow()->domain('nozone.example');

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'nozone.example', 'keySize' => 1024])->assertCreated()
            ->assertJsonPath('data.dnsPublication.status', 'noZone');
        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'sub.parent.example', 'keySize' => 1024])->assertCreated()
            ->assertJsonPath('data.dnsPublication.status', 'noZone');

        $this->assertNotNull($this->dkimField('DKIM_SELECTORS', 'nozone.example'));
        $this->ispconfig()->assertCallCount('dns_txt_add', 0);
    }

    public function test_an_unreachable_server_never_undoes_the_key_change(): void
    {
        $this->ispconfig()->failOn('login', 'unreachable', 'Connection refused');
        $started = $this->clock()->now();

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024])->assertCreated()
            ->assertJsonPath('data.dnsPublication.status', 'failed')
            ->assertJsonPath('data.dnsPublication.reason', ['code' => 'unreachable', 'detail' => 'Connection refused']);
        $this->assertLessThanOrEqual(15000, $this->clock()->now()->getTimestampMs() - $started->getTimestampMs());
        $this->assertNotNull($this->dkimField('DKIM_SELECTORS', 'example.org'));

        $this->send('DELETE', '/api/v2/dkim-keys/example.org')->assertNoContent();
        $this->assertNoDkim('example.org');
        $deletion = $this->onlyAudit('dkimKeyDeletion')->details['dnsPublication'];
        $this->assertSame(['failed', 'unreachable'], [$deletion['status'], $deletion['reasonCode']]);
    }

    public function test_deletions_record_whether_a_record_was_removed(): void
    {
        $this->mailcow()->dkimKey('example.org', 'dkim', 1024);
        $this->mailcow()->dkimKey('b.example', 'dkim', 1024);
        $this->ispconfig()->record(10, 'dkim._domainkey', 'TXT', self::value(DkimFixtures::RSA_1024_PUBLIC));

        $this->send('DELETE', '/api/v2/dkim-keys/example.org')->assertNoContent();
        $this->send('DELETE', '/api/v2/dkim-keys/b.example')->assertNoContent();

        $this->assertSame(['removed', 'noRecordToRemove'], array_map(fn ($r) => $r->details['dnsPublication']['status'], $this->auditOfType('dkimKeyDeletion')));
        $this->ispconfig()->assertCallCount('dns_txt_delete', 1);
    }

    public function test_a_record_is_published_again_after_a_failure(): void
    {
        $this->ispconfig()->failOn('login', 'unreachable');
        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024])->assertCreated()->assertJsonPath('data.dnsPublication.status', 'failed');
        $this->ispconfig()->recover();

        $tag = (string) $this->getJson('/api/v2/dkim-keys/example.org')->headers->get('ETag');
        $this->send('POST', '/api/v2/dkim-keys/example.org/dns-publication', null, ['If-Match' => '"stale"'])->assertStatus(412);

        $response = $this->send('POST', '/api/v2/dkim-keys/example.org/dns-publication', null, ['If-Match' => $tag])->assertOk()
            ->assertHeader('ETag', $tag)
            ->assertJsonPath('data.dnsPublication.status', 'published')
            ->assertJsonPath('data.key.selector', 'dkim');

        $this->ispconfig()->assertRecord(10, 'dkim._domainkey', self::value((string) $response->json('data.key.publicKey')));
        $records = $this->auditOfType('dkimDnsPublication');
        $this->assertSame([['failure', 'versionOutdated'], ['success', null]], array_map(fn ($r) => [$r->outcome, $r->failure_reason], $records));
        $this->assertSame(['selector' => 'dkim', 'dnsPublication' => ['status' => 'published', 'reasonCode' => null, 'detail' => null]], $records[1]->details);
    }

    public function test_a_name_without_a_key_is_not_found(): void
    {
        $this->send('POST', '/api/v2/dkim-keys/example.org/dns-publication')->assertNotFound();
    }

    public function test_a_disabled_publication_never_contacts_ispconfig(): void
    {
        config(['restv2.dkim.dns_publication' => false]);

        $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024])->assertCreated()
            ->assertJsonPath('data.dnsPublication.status', 'notConfigured');
        $this->send('POST', '/api/v2/dkim-keys/example.org/dns-publication')->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/dkim-dns-publication-disabled');

        config(['restv2.dkim.dns_publication' => true, 'restv2.ispconfig.url' => '']);
        $this->send('POST', '/api/v2/dkim-keys/example.org/dns-publication')->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/dkim-dns-publication-disabled');

        $this->ispconfig()->assertNoCalls();
        $this->assertSame(['featureDisabled', 'featureDisabled'], array_column($this->auditOfType('dkimDnsPublication'), 'failure_reason'));
    }

    public function test_domain_administrators_and_read_only_keys_cannot_publish_again(): void
    {
        $this->mailcow()->dkimKey('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->send('POST', '/api/v2/dkim-keys/example.org/dns-publication')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->send('POST', '/api/v2/dkim-keys/example.org/dns-publication', null, $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->ispconfig()->assertNoCalls();
    }

    public function test_the_ispconfig_password_appears_nowhere(): void
    {
        $this->ispconfig()->failOn('login', 'authenticationFailed', 'Login failed for remote with IspSecret-42');

        $response = $this->send('POST', '/api/v2/dkim-keys', ['name' => 'example.org', 'keySize' => 1024])->assertCreated()
            ->assertJsonPath('data.dnsPublication.reason.code', 'authenticationFailed');

        $this->assertStringNotContainsString('IspSecret-42', (string) $response->baseResponse->getContent());
        $this->assertStringContainsString('***', (string) $response->json('data.dnsPublication.reason.detail'));
        $this->assertStringNotContainsString('IspSecret-42', (string) DB::table('restv2_audit_events')->get()->toJson());
        $this->assertStringNotContainsString('IspSecret-42', implode("\n", $this->logged));
    }
}
