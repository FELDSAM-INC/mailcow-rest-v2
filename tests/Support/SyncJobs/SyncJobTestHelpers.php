<?php

namespace Tests\Support\SyncJobs;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailboxes\MailboxTestHelpers;

/**
 * Callers, rows, requests and lookups shared by the tests of feature 008. Used in Tests\TestCase
 * subclasses.
 */
trait SyncJobTestHelpers
{
    use MailboxTestHelpers;

    /** The remote password of every job the tests create through the API. */
    protected const REMOTE_PASSWORD = 'Remote-S3cret!';

    /**
     * A mailbox user (and their domain) with or without the `syncjobs` flag, signed in.
     */
    protected function asSyncUser(string $username = 'alice@example.org', bool $syncjobs = true): void
    {
        if (! DB::table('mailbox')->where('username', $username)->exists()) {
            $this->mailcow()->mailboxWithDomain($username, userAcl: ['syncjobs' => (int) $syncjobs]);
        }

        $this->signInAs($username, Role::MailboxUser);
    }

    /**
     * A mailbox (and its domain) whose user may or may not use sync jobs.
     */
    protected function syncMailbox(string $username, bool $syncjobs = true): void
    {
        if (! DB::table('mailbox')->where('username', $username)->exists()) {
            $this->mailcow()->mailboxWithDomain($username, userAcl: ['syncjobs' => (int) $syncjobs]);
        }
    }

    /**
     * @phpstan-impure
     */
    protected function syncJobRow(int $id): ?stdClass
    {
        $row = DB::table('imapsync')->where('id', $id)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * @phpstan-impure
     */
    protected function syncJobCount(): int
    {
        return DB::table('imapsync')->count();
    }

    /**
     * The minimal body of a creation: the four required members.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected static function syncJobBody(array $extra = []): array
    {
        return array_merge([
            'remoteHost' => 'imap.old-provider.example',
            'remotePort' => 993,
            'remoteUser' => 'alice.old',
            'remotePassword' => self::REMOTE_PASSWORD,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers  an `X-API-Key` header replaces the session's token
     * @return TestResponse<Response>
     */
    protected function createSyncJob(array $body, array $headers = []): TestResponse
    {
        return $this->postJson('/api/v2/sync-jobs', $body, $headers + (isset($headers['X-API-Key']) ? [] : $this->csrf()));
    }

    /**
     * @param  array<string, mixed>  $patch
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function patchSyncJob(int $id, array $patch, array $headers = []): TestResponse
    {
        return $this->patchMerge('/api/v2/sync-jobs/'.$id, $patch, $headers + (isset($headers['X-API-Key']) ? [] : $this->csrf()));
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function deleteSyncJob(int $id, array $headers = []): TestResponse
    {
        return $this->deleteJson('/api/v2/sync-jobs/'.$id, [], $headers + (isset($headers['X-API-Key']) ? [] : $this->csrf()));
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function rescheduleSyncJob(int $id, array $headers = []): TestResponse
    {
        return $this->postEmpty('/api/v2/sync-jobs/'.$id.'/schedule-reset', $headers + (isset($headers['X-API-Key']) ? [] : $this->csrf()));
    }

    /**
     * @param  list<array<string, string>|int>  $targets  IDs, or target objects as sent
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function syncJobBulk(string $action, array $targets, array $headers = []): TestResponse
    {
        $targets = array_map(fn (array|int $t): array => is_int($t) ? ['id' => (string) $t] : $t, $targets);

        return $this->postJson('/api/v2/sync-jobs/bulk-actions', ['action' => $action, 'targets' => $targets], $headers + (isset($headers['X-API-Key']) ? [] : $this->csrf()));
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<int>
     */
    protected function idsOf(TestResponse $response): array
    {
        return array_map('intval', array_column((array) $response->json('data'), 'id'));
    }
}
