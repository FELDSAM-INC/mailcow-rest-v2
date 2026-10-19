<?php

namespace Tests\Feature\Quarantine;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T046 (FR-072, AS-2.9): every role and both key types read the limits and server
 * settings of the quarantine, spam policy and rate limit forms; the values follow the server's
 * configuration, and reading writes no audit record.
 */
class QuarantineCapabilitiesTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
        $this->quarantineMailbox('anna@example.org', self::NO_USER_FLAGS);
    }

    public function test_every_role_and_key_reads_the_capabilities(): void
    {
        $callers = [
            fn () => $this->signInAs('admin', Role::Admin),
            fn () => $this->signInAs('reseller', Role::DomainAdmin),
            fn () => $this->signInAs('anna@example.org', Role::MailboxUser),
        ];

        foreach ($callers as $signIn) {
            $this->forgetCookies();
            $signIn();
            $this->assertCapabilities($this->getJson('/api/v2/capabilities/quarantine')->assertOk()->json('data'));
        }

        $this->forgetCookies();

        foreach ([['admin', 'readWrite'], ['admin', 'readOnly'], ['reseller', 'readWrite'], ['reseller', 'readOnly']] as [$owner, $level]) {
            $this->getJson('/api/v2/capabilities/quarantine', $this->keyHeader($this->apiKey($owner, $level)))->assertOk();
        }

        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }

    /**
     * @param  mixed  $data
     */
    private function assertCapabilities($data): void
    {
        $this->assertIsArray($data);
        $this->assertSame(100, $data['bulkLimit']);
        $this->assertSame(25, $data['defaultPageSize']);
        $this->assertSame(1000, $data['maxPageSize']);
        $this->assertSame(10485760, $data['contentSizeLimit']);
        $this->assertSame(10485760, $data['pipelineMaxMessageSize']);
        $this->assertEquals(['min' => 0, 'max' => 5000, 'step' => 0.1], $data['spamScore']);
        $this->assertSame(100, $data['senderPattern']['maxLength']);
        $this->assertSame('a-z0-9@_.*-', $data['senderPattern']['allowedCharacters']);
        $this->assertSame('*', $data['senderPattern']['wildcard']);
        $this->assertSame(['min' => 1, 'max' => 2147483647, 'frames' => ['second', 'minute', 'hour', 'day']], $data['rateLimit']);
        $this->assertSame(1048576, $data['settings']['maxMessageSizeStep']);
        $this->assertSame(1048576, $data['settings']['notificationTemplateMaxBytes']);
        $this->assertSame(5, $data['settings']['templateRenderTimeoutSeconds']);
        $this->assertSame(['original', 'attachment'], $data['settings']['releaseFormats']);
        $this->assertSame(['min' => 1, 'max' => 2147483647, 'default' => 365], $data['settings']['maxAgeDays']);
        $this->assertSame(2147483647, $data['settings']['maxRetentionPerMailbox']);
        $this->assertSame(255, $data['settings']['notificationSubjectMaxLength']);
        $this->assertTrue($data['virusScanning']);
    }

    public function test_the_values_follow_the_configuration(): void
    {
        config(['restv2.skip_clamd' => 'y', 'restv2.quarantine.content_limit' => 5242880]);
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $data = (array) $this->getJson('/api/v2/capabilities/quarantine')->assertOk()->json('data');

        $this->assertFalse($data['virusScanning']);
        $this->assertSame(5242880, $data['contentSizeLimit']);
    }
}
