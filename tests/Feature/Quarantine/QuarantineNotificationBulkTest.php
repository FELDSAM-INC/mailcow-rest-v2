<?php

namespace Tests\Feature\Quarantine;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T059 (FR-037, AS-4.3): administrators and domain administrators set the notification
 * frequency, the category or both for up to 100 mailboxes, with one result per distinct mailbox.
 */
class QuarantineNotificationBulkTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('example.net');
        $this->mailcow()->domain('other.example');
        $this->mailcow()->domainAdmin('reseller', ['example.org', 'example.net'], scheme: 'SSHA256');
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function bulk(array $body, array $headers = []): TestResponse
    {
        return $this->send('POST', '/api/v2/quarantine-notification-settings/bulk-actions', $body, $headers);
    }

    public function test_a_domain_admin_sets_weekly_on_thirty_mailboxes(): void
    {
        $targets = [];

        for ($i = 0; $i < 30; $i++) {
            $address = sprintf('user%02d@%s', $i, $i % 2 === 0 ? 'example.org' : 'example.net');
            $this->quarantineMailbox($address);
            $targets[] = ['id' => $address];
        }

        $this->quarantineMailbox('otto@other.example');
        $targets[] = ['id' => 'otto@other.example'];
        $this->signInAs('reseller', Role::DomainAdmin);

        $response = $this->bulk(['frequency' => 'weekly', 'targets' => $targets])->assertOk();

        $results = (array) $response->json('data.results');
        $this->assertSame(array_merge(array_fill(0, 30, 'done'), ['notFound']), array_column($results, 'outcome'));
        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]+"$/', $results[0]['version']);
        $this->assertSame($results[0]['version'], $this->getJson('/api/v2/mailboxes/user00@example.org/quarantine-notification-settings')->json('data.etag'));
        $this->assertSame(30, DB::table('mailbox')->whereRaw("JSON_VALUE(`attributes`, '$.quarantine_notification') = 'weekly'")->count());
        $bulkRequestId = $response->json('data.bulkRequestId');
        $records = DB::table('restv2_audit_events')->where('event_type', 'quarantineNotificationSettingsUpdate')->get();
        $this->assertCount(31, $records);
        foreach ($records as $record) {
            $this->assertSame($bulkRequestId, json_decode((string) $record->details, true)['bulkRequestId']);
        }
    }

    public function test_outdated_duplicate_and_resource_targets(): void
    {
        $this->quarantineMailbox('anna@example.org');
        $this->quarantineMailbox('bert@example.org');
        $this->quarantineMailbox('room@example.org', kind: 'location');
        $this->asAdmin();

        $response = $this->bulk(['category' => 'junk', 'targets' => [
            ['id' => 'anna@example.org', 'version' => '"stale"'],
            ['id' => 'bert@example.org'],
            ['id' => 'BERT@example.org'],
            ['id' => 'room@example.org'],
        ]])->assertOk();

        $this->assertSame(['outdated', 'done', 'notFound'], array_column((array) $response->json('data.results'), 'outcome'));
    }

    public function test_mailbox_users_and_read_only_keys_are_refused(): void
    {
        $this->asQuarantineUser('anna@example.org');
        $this->bulk(['frequency' => 'daily', 'targets' => [['id' => 'anna@example.org']]])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->bulk(['frequency' => 'daily', 'targets' => [['id' => 'anna@example.org']]], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
    }

    public function test_invalid_bodies_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->bulk(['targets' => [['id' => 'anna@example.org']]])->assertStatus(422)->assertJsonPath('errors.body.0.code', 'required');
        $this->bulk(['frequency' => 'daily', 'targets' => array_map(fn (int $i): array => ['id' => "u{$i}@example.org"], range(1, 101))])
            ->assertStatus(422)
            ->assertJsonPath('errors.targets.0.code', 'tooMany');
    }
}
