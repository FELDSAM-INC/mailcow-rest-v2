<?php

namespace Tests\Unit\SyncJobs;

use App\Models\Mailcow\SyncJob;
use App\Services\SyncJobs\SyncJobAuditDetails;
use Tests\TestCase;

/**
 * Feature 008 T020 (FR-018, FR-025, SC-007): sync-job audit details are in contract member names
 * and never contain the remote password, whatever the stored or changed values; a replaced
 * password appears only as "changed".
 */
class SyncJobAuditDetailsTest extends TestCase
{
    private const SECRET = 'Remote-S3cret!';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function job(string $password, array $overrides = []): SyncJob
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $id = $this->mailcow()->syncJob('alice@example.org', array_merge(['password1' => $password], $overrides));

        return SyncJob::query()->findOrFail($id);
    }

    public function test_creation_values_hold_the_configuration_and_only_whether_a_password_is_set(): void
    {
        $values = SyncJobAuditDetails::values($this->job(self::SECRET, ['custom_params' => '--maxsize=1']));

        $this->assertSame('alice@example.org', $values['mailbox']);
        $this->assertSame('ssl', $values['encryption']);
        $this->assertSame('--maxsize=1', $values['customParameters']);
        $this->assertTrue($values['remotePasswordSet']);
        $this->assertArrayNotHasKey('etag', $values);
        $this->assertArrayNotHasKey('runStatus', $values);
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($values));
    }

    public function test_changes_list_changed_members_and_the_password_only_as_changed(): void
    {
        $before = SyncJobAuditDetails::configuration($this->job(self::SECRET));
        $after = array_merge($before, ['intervalMinutes' => 30, 'active' => false]);

        $changes = SyncJobAuditDetails::changes($before, $after, true);

        $this->assertSame([
            'intervalMinutes' => ['old' => 20, 'new' => 30],
            'active' => ['old' => true, 'new' => false],
            'remotePassword' => 'changed',
        ], $changes);
        $this->assertSame('{}', json_encode(SyncJobAuditDetails::changes($before, $before, false)));
    }

    public function test_no_output_contains_a_password_even_when_a_password_is_sent_in_any_member(): void
    {
        $job = $this->job(self::SECRET);
        $before = SyncJobAuditDetails::configuration($job);
        $after = array_merge($before, ['remotePassword' => self::SECRET, 'password1' => self::SECRET, 'password' => self::SECRET]);

        $json = json_encode([SyncJobAuditDetails::values($job), SyncJobAuditDetails::changes($before, $after, true)]);

        $this->assertStringNotContainsString(self::SECRET, (string) $json);
        $this->assertStringNotContainsString(hash('sha256', self::SECRET), (string) $json);
    }
}
