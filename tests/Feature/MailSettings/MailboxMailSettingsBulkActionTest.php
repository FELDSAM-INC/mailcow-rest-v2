<?php

namespace Tests\Feature\MailSettings;

use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Services\Mailboxes\TaggedMailHandlingStore;
use App\Services\MailSettings\MailSettingsService;
use App\Services\SelfService\PermissionGate;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T088 (feature 005 FR-060, US7 AS-4): the bulk TLS change, one result and one audit record per
 * mailbox.
 */
class MailboxMailSettingsBulkActionTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/mailboxes/mail-settings/bulk-actions';

    public function test_thirty_mailboxes_with_one_deleted(): void
    {
        $this->asAdmin();
        $targets = [];

        for ($i = 1; $i <= 30; $i++) {
            $this->mailcow()->mailboxWithDomain("user{$i}@example.org");
            $targets[] = ['id' => "user{$i}@example.org"];
        }

        DB::table('mailbox')->where('username', 'user7@example.org')->delete();

        $response = $this->postJson(self::URL, ['action' => 'setTlsEnforcement', 'tlsEnforceOutbound' => true, 'targets' => $targets], $this->csrf())
            ->assertOk()
            ->assertJsonPath('data.summary', ['total' => 30, 'done' => 29, 'notFound' => 1, 'refused' => 0, 'outdated' => 0, 'failed' => 0]);

        $this->assertSame('notFound', $response->json('data.results.6.outcome'));
        $this->assertSame('done', $response->json('data.results.0.outcome'));
        $this->assertSame($this->getJson('/api/v2/mailboxes/user1@example.org/mail-settings')->json('data.etag'), $response->json('data.results.0.version'));
        $this->assertSame(1, $this->mailboxAttributes('user1@example.org')['tls_enforce_out']);
        $records = array_filter($this->auditRecords('mailSettingsUpdate'), fn (object $r): bool => $r->outcome === 'success');
        $this->assertCount(29, $records);
        $this->assertCount(1, array_unique(array_map(fn (object $r): string => $r->details['bulkRequestId'], $records)));
    }

    public function test_outdated_out_of_scope_and_duplicate_targets(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->mailcow()->mailboxWithDomain('dora@example.net');
        $this->asDomainAdmin(['example.org']);

        $response = $this->postJson(self::URL, ['action' => 'setTlsEnforcement', 'tlsEnforceInbound' => true, 'targets' => [
            ['id' => 'anna@example.org', 'version' => '"stale"'],
            ['id' => 'bob@example.org'],
            ['id' => 'BOB@example.org'],
            ['id' => 'dora@example.net'],
        ]], $this->csrf())->assertOk();

        $this->assertSame(['outdated', 'done', 'notFound'], array_column($response->json('data.results'), 'outcome'));
        $this->assertSame('0', $this->mailboxAttributes('anna@example.org')['tls_enforce_in']);
    }

    /** G22: the mailbox list carries the version a bulk target sends. */
    public function test_the_version_from_the_mailbox_list(): void
    {
        $this->asAdmin();
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailbox('bob@example.org');
        app(TaggedMailHandlingStore::class)->set('anna@example.org', 'subject');

        $list = $this->getJson('/api/v2/mailboxes')->assertOk()->json('data');
        $versions = array_column($list, 'mailSettingsVersion', 'address');
        $this->assertSame($this->getJson('/api/v2/mailboxes/anna@example.org/mail-settings')->json('data.etag'), $versions['anna@example.org']);
        $this->assertNotSame($versions['anna@example.org'], $versions['bob@example.org']);

        $this->postJson(self::URL, ['action' => 'setTlsEnforcement', 'tlsEnforceInbound' => true, 'targets' => [
            ['id' => 'anna@example.org', 'version' => $versions['anna@example.org']],
            ['id' => 'bob@example.org', 'version' => $versions['anna@example.org']],
        ]], $this->csrf())->assertOk()->assertJsonPath('data.summary.done', 1)->assertJsonPath('data.summary.outdated', 1);
    }

    public function test_invalid_requests_and_refused_callers(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $targets = array_map(fn (int $i): array => ['id' => "user{$i}@example.org"], range(1, 101));

        $this->postJson(self::URL, ['action' => 'setTlsEnforcement', 'tlsEnforceInbound' => true, 'targets' => $targets], $this->csrf())->assertStatus(422)->assertJsonPath('errors.targets.0.code', 'tooMany');
        $this->postJson(self::URL, ['action' => 'setTlsEnforcement', 'targets' => [['id' => 'a@example.org']]], $this->csrf())->assertStatus(422);

        $this->asSelfServiceUser();
        $this->postJson(self::URL, ['action' => 'setTlsEnforcement', 'tlsEnforceInbound' => true, 'targets' => [['id' => 'anna@example.org']]], $this->csrf())->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->postJson(self::URL, ['action' => 'setTlsEnforcement', 'tlsEnforceInbound' => true, 'targets' => [['id' => 'anna@example.org']]], $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
    }

    public function test_a_failing_item_does_not_stop_the_others(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->asAdmin();
        $this->app->bind(MailSettingsService::class, fn ($app) => new class($app->make(TaggedMailHandlingStore::class), $app->make(PermissionGate::class), $app->make(AuditLogger::class)) extends MailSettingsService
        {
            public function update(Principal $principal, string $mailbox, array $patch, ?string $ifMatch, ?string $bulkRequestId = null): array
            {
                if ($mailbox === 'anna@example.org') {
                    throw new RuntimeException('disk full');
                }

                return parent::update($principal, $mailbox, $patch, $ifMatch, $bulkRequestId);
            }
        });

        $response = $this->postJson(self::URL, ['action' => 'setTlsEnforcement', 'tlsEnforceOutbound' => true, 'targets' => [['id' => 'anna@example.org'], ['id' => 'bob@example.org']]], $this->csrf())->assertOk();

        $this->assertSame(['failed', 'done'], array_column($response->json('data.results'), 'outcome'));
        $this->assertSame(500, $response->json('data.results.0.problem.status'));
    }
}
