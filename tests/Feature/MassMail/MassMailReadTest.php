<?php

namespace Tests\Feature\MassMail;

use Illuminate\Support\Facades\DB;
use Tests\Support\MassMail\MassMailTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T070 (FR-077, FR-080, FR-081, AS-6.8, gaps G16 and G17): administrators list jobs as
 * summaries, read one job with its bodies and counts, and list its recipients with their outcome,
 * failure code and Dovecot's reply.
 */
class MassMailReadTest extends TestCase
{
    use MassMailTestHelpers;

    private string $older;

    private string $newer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->older = $this->runningJob(['a@example.org', 'b@example.org', 'c@example.org', 'd@example.org'], ['subject' => 'Old maintenance', 'created_at' => '2026-10-01 10:00:00.000000', 'html_body' => '<p>x</p>']);
        DB::table('restv2_mass_mail_jobs')->where('id', $this->older)->update(['status' => 'completed', 'running_slot' => null, 'started_at' => '2026-10-01 10:00:01.000000', 'finished_at' => '2026-10-01 10:01:00.000000']);
        DB::table('restv2_mass_mail_recipients')->where('job_id', $this->older)->where('address', 'a@example.org')->update(['outcome' => 'delivered', 'attempts' => 1, 'last_attempt_at' => '2026-10-01 10:00:01.000000']);
        DB::table('restv2_mass_mail_recipients')->where('job_id', $this->older)->where('address', 'b@example.org')->update(['outcome' => 'failed', 'attempts' => 1, 'failure_reason' => '552 5.2.2 Mailbox is full', 'failure_code' => 'overQuota']);
        DB::table('restv2_mass_mail_recipients')->where('job_id', $this->older)->where('address', 'c@example.org')->update(['outcome' => 'skipped']);
        DB::table('restv2_mass_mail_recipients')->where('job_id', $this->older)->where('address', 'd@example.org')->update(['outcome' => 'delivered']);
        $this->newer = $this->runningJob(['x@example.org', 'y@example.org'], ['subject' => 'New announcement', 'created_at' => '2026-10-19 08:00:00.000000']);
        DB::table('restv2_mass_mail_recipients')->where('job_id', $this->newer)->where('address', 'x@example.org')->update(['outcome' => 'delivering', 'attempts' => 1]);
    }

    public function test_jobs_are_listed_newest_first_as_summaries(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/mass-mail-jobs')->assertOk();

        $this->assertSame([$this->newer, $this->older], array_column((array) $response->json('data'), 'id'));
        $response->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.counts', ['pending' => 2, 'delivered' => 0, 'failed' => 0, 'skipped' => 0])
            ->assertJsonPath('data.1.counts', ['pending' => 0, 'delivered' => 2, 'failed' => 1, 'skipped' => 1]);
        $this->assertArrayNotHasKey('textBody', (array) $response->json('data.0'));
        $this->assertArrayNotHasKey('htmlBody', (array) $response->json('data.0'));
        $this->assertSame([$this->older, $this->newer], array_column((array) $this->getJson('/api/v2/mass-mail-jobs?order=asc')->json('data'), 'id'));
        $this->assertSame([$this->older, $this->newer], array_column((array) $this->getJson('/api/v2/mass-mail-jobs?sort=status&order=asc')->json('data'), 'id'));
        $this->assertSame([$this->older], array_column((array) $this->getJson('/api/v2/mass-mail-jobs?status=completed')->json('data'), 'id'));
        $this->assertSame([$this->newer], array_column((array) $this->getJson('/api/v2/mass-mail-jobs?q=ANNOUNCE')->json('data'), 'id'));
    }

    public function test_a_job_is_read_with_its_bodies_times_and_counts(): void
    {
        $this->asAdmin();

        $this->getJson("/api/v2/mass-mail-jobs/{$this->older}")->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.sender', 'noreply@mail.example.org')
            ->assertJsonPath('data.subject', 'Old maintenance')
            ->assertJsonPath('data.textBody', "Maintenance tonight.\n")
            ->assertJsonPath('data.htmlBody', '<p>x</p>')
            ->assertJsonPath('data.createdBy', ['username' => 'admin'])
            ->assertJsonPath('data.createdAt', '2026-10-01T10:00:00Z')
            ->assertJsonPath('data.startedAt', '2026-10-01T10:00:01Z')
            ->assertJsonPath('data.finishedAt', '2026-10-01T10:01:00Z')
            ->assertJsonPath('data.recipientCount', 4);
        $this->getJson('/api/v2/mass-mail-jobs/01JABCDEFGHJKMNPQRSTVWXYZ0')->assertNotFound();
    }

    public function test_recipients_are_listed_with_outcomes_and_failure_codes(): void
    {
        $this->asAdmin();

        $response = $this->getJson("/api/v2/mass-mail-jobs/{$this->older}/recipients")->assertOk();

        $this->assertSame(['a@example.org', 'b@example.org', 'c@example.org', 'd@example.org'], array_column((array) $response->json('data'), 'address'));
        $response->assertJsonPath('data.1', ['address' => 'b@example.org', 'outcome' => 'failed', 'failureCode' => 'overQuota', 'failureReason' => '552 5.2.2 Mailbox is full', 'attempts' => 1, 'lastAttemptAt' => null])
            ->assertJsonPath('data.0.lastAttemptAt', '2026-10-01T10:00:01Z')
            ->assertJsonPath('data.0.failureCode', null);
        $this->assertSame(['b@example.org'], array_column((array) $this->getJson("/api/v2/mass-mail-jobs/{$this->older}/recipients?outcome=failed")->json('data'), 'address'));
        $this->assertSame(['c@example.org'], array_column((array) $this->getJson("/api/v2/mass-mail-jobs/{$this->older}/recipients?q=c@")->json('data'), 'address'));
        $this->assertSame(['d@example.org', 'c@example.org', 'b@example.org', 'a@example.org'], array_column((array) $this->getJson("/api/v2/mass-mail-jobs/{$this->older}/recipients?order=desc")->json('data'), 'address'));
        $this->getJson("/api/v2/mass-mail-jobs/{$this->older}/recipients?sort=outcome")->assertOk();
        $pending = $this->getJson("/api/v2/mass-mail-jobs/{$this->newer}/recipients?outcome=pending")->assertOk();
        $this->assertSame(['x@example.org', 'y@example.org'], array_column((array) $pending->json('data'), 'address'));
        $pending->assertJsonPath('data.0.outcome', 'pending');
        $this->getJson('/api/v2/mass-mail-jobs/01JABCDEFGHJKMNPQRSTVWXYZ0/recipients')->assertNotFound();
    }

    public function test_a_read_only_key_reads_and_other_roles_are_forbidden(): void
    {
        $this->getJson('/api/v2/mass-mail-jobs', $this->adminKey('readOnly'))->assertOk();
        $this->getJson("/api/v2/mass-mail-jobs/{$this->older}", $this->adminKey('readOnly'))->assertOk();

        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);
        $this->getJson('/api/v2/mass-mail-jobs')->assertForbidden();
        $this->getJson("/api/v2/mass-mail-jobs/{$this->older}")->assertForbidden();
        $this->getJson("/api/v2/mass-mail-jobs/{$this->older}/recipients")->assertForbidden();
    }
}
