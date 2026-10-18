<?php

namespace Tests\Feature\SyncJobs;

use Illuminate\Support\Facades\DB;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T024 (FR-009, research R14): the run log is the runner's stored output, returned
 * whole and as valid JSON (invalid UTF-8 replaced by U+FFFD), with the run status; reading it needs
 * the same access as the job and is not audited.
 */
class SyncJobLogTest extends TestCase
{
    use SyncJobTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncMailbox('alice@example.org');
        $this->syncMailbox('carol@example.net');
    }

    public function test_the_stored_output_is_returned_unchanged_with_the_run_status(): void
    {
        $log = "Transfer started at Sat Oct 18 10:00:00 2026\nHost1: imap.example.net says it has CAPABILITY for AUTHENTICATE LOGIN\n<b>not html</b> & \"quoted\"\n";
        $id = $this->mailcow()->syncJob('alice@example.org', ['returned_text' => $log, 'success' => 1, 'exit_status' => 'EX_OK', 'last_run' => '2026-10-18 10:05:00']);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/sync-jobs/'.$id.'/log')->assertOk();

        $this->assertSame($id, $response->json('data.syncJobId'));
        $this->assertSame($log, $response->json('data.log'));
        $this->assertSame('succeeded', $response->json('data.runStatus.lastResult'));
        $this->assertTrue($response->json('data.runStatus.logAvailable'));
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }

    public function test_a_missing_log_is_an_empty_string(): void
    {
        $id = $this->mailcow()->syncJob('alice@example.org', ['returned_text' => null]);
        $this->asAdmin();

        $this->getJson('/api/v2/sync-jobs/'.$id.'/log')->assertOk()
            ->assertJsonPath('data.log', '')
            ->assertJsonPath('data.runStatus.logAvailable', false);
    }

    public function test_invalid_utf8_is_replaced_and_the_response_stays_valid_json(): void
    {
        $id = $this->mailcow()->syncJob('alice@example.org');
        // The runner may store bytes of remote folder names that are not UTF-8: written here
        // without the server's conversion, as raw bytes.
        DB::statement('SET NAMES binary');
        DB::update('UPDATE imapsync SET returned_text = ? WHERE id = ?', ["folder \xC3\x28 and \xFF end", $id]);
        DB::statement("SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'");
        $this->asAdmin();

        $response = $this->getJson('/api/v2/sync-jobs/'.$id.'/log')->assertOk();

        $this->assertNotNull(json_decode((string) $response->getContent(), true));
        $this->assertSame("folder \u{FFFD}( and \u{FFFD} end", $response->json('data.log'));
    }

    public function test_a_five_megabyte_log_is_returned_whole(): void
    {
        $log = str_repeat("msg 1234567890 copied to INBOX/Archive\n", 135000);
        $this->assertGreaterThan(5 * 1024 * 1024, strlen($log));
        $id = $this->mailcow()->syncJob('alice@example.org', ['returned_text' => $log]);
        $this->asAdmin();

        $this->assertSame(strlen($log), strlen((string) $this->getJson('/api/v2/sync-jobs/'.$id.'/log')->assertOk()->json('data.log')));
    }

    public function test_a_foreign_job_is_not_found_and_a_caller_without_the_flag_is_refused(): void
    {
        $foreign = $this->mailcow()->syncJob('carol@example.net', ['returned_text' => 'secret folder names']);
        $own = $this->mailcow()->syncJob('alice@example.org', ['returned_text' => 'mine']);

        $this->asSyncUser('alice@example.org');
        $this->getJson('/api/v2/sync-jobs/'.$foreign.'/log')->assertNotFound()->assertJsonMissingPath('data');
        $this->getJson('/api/v2/sync-jobs/'.$own.'/log')->assertOk()->assertJsonPath('data.log', 'mine');

        DB::table('user_acl')->where('username', 'carol@example.net')->update(['syncjobs' => 0]);
        $this->asSyncUser('carol@example.net');
        $this->getJson('/api/v2/sync-jobs/'.$foreign.'/log')->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['syncjobs']);
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }
}
