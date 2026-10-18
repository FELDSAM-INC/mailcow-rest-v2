<?php

namespace Tests\Feature\SyncJobs;

use App\Services\Config\MailcowConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\SyncJobs\ImapsyncRunnerSimulator;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T036 (SC-002, research R10): a job created through the API leaves the row legacy's
 * mailbox('add', 'syncjob') (functions.mailbox.inc.php, upstream 2026-09a) inserts for the same
 * input, column by column; every column outside legacy's insert list keeps its database default.
 * The runner selects an active new job. No other table and no Redis key changes.
 */
class CreateSyncJobRowParityTest extends TestCase
{
    use SyncJobTestHelpers;

    /** Columns that differ by nature: the generated identifier and the times. */
    private const VOLATILE = ['id', 'created', 'modified'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->syncMailbox('alice@example.org');
        $this->asAdmin();
    }

    /**
     * The row legacy writes: its INSERT column list with the values its add form posts, and the
     * schema defaults for every other column.
     *
     * @param  array<string, mixed>  $insert
     * @return array<string, mixed>
     */
    private static function legacyRow(array $insert): array
    {
        return array_merge([
            'authmech1' => 'PLAIN', 'regextrans2' => '', 'authmd51' => 0, 'domain2' => '', 'is_running' => 0,
            'returned_text' => null, 'last_run' => null, 'success' => null, 'exit_status' => null,
        ], $insert);
    }

    /**
     * @return array<string, mixed>
     */
    private function storedRow(int $id): array
    {
        $row = (array) $this->syncJobRow($id);

        foreach (self::VOLATILE as $column) {
            unset($row[$column]);
        }

        foreach ($row as $column => $value) {
            $row[$column] = is_int($value) || $value === null || ! is_numeric($value) || $column === 'maxbytespersecond' ? $value : (int) $value;
        }

        ksort($row);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    private function assertRow(array $expected, int $id): void
    {
        ksort($expected);

        $this->assertSame($expected, $this->storedRow($id));
    }

    public function test_minimal_input_leaves_legacy_s_row_from_the_add_form_defaults(): void
    {
        $id = (int) $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->assertCreated()->json('data.id');

        // mailbox('add', 'syncjob') with the add modal's pre-filled values.
        $this->assertRow(self::legacyRow([
            'user2' => 'alice@example.org', 'exclude' => '(?i)spam|(?i)junk', 'delete1' => 0, 'delete2' => 0,
            'timeout1' => 600, 'timeout2' => 600, 'automap' => 1, 'skipcrossduplicates' => 0, 'maxbytespersecond' => '0',
            'subscribeall' => 1, 'dry' => 0, 'maxage' => 0, 'subfolder2' => '', 'host1' => 'imap.old-provider.example',
            'user1' => 'alice.old', 'password1' => self::REMOTE_PASSWORD, 'mins_interval' => 20, 'port1' => 993, 'enc1' => 'SSL',
            'delete2duplicates' => 1, 'custom_params' => '', 'active' => 1,
        ]), $id);
    }

    public function test_every_option_set_leaves_legacy_s_row(): void
    {
        $id = (int) $this->createSyncJob([
            'mailbox' => 'alice@example.org', 'remoteHost' => 'Mail.Example.NET', 'remotePort' => 143, 'encryption' => 'starttls',
            'remoteUser' => 'alice', 'remotePassword' => 'pw', 'exclude' => 'Trash', 'destinationSubfolder' => 'Imported',
            'intervalMinutes' => 60, 'maxAgeDays' => 30, 'maxBytesPerSecond' => 100000, 'remoteTimeoutSeconds' => 120,
            'localTimeoutSeconds' => 240, 'deleteFromSource' => true, 'deleteFromDestination' => true,
            'deleteDuplicatesOnDestination' => false, 'automap' => false, 'skipCrossDuplicates' => true, 'subscribeAll' => false,
            'dryRun' => true, 'customParameters' => '--maxsize=1000', 'active' => false,
        ])->assertCreated()->json('data.id');

        $this->assertRow(self::legacyRow([
            'user2' => 'alice@example.org', 'exclude' => 'Trash', 'delete1' => 1, 'delete2' => 1, 'timeout1' => 120,
            'timeout2' => 240, 'automap' => 0, 'skipcrossduplicates' => 1, 'maxbytespersecond' => '100000', 'subscribeall' => 0,
            'dry' => 1, 'maxage' => 30, 'subfolder2' => 'Imported', 'host1' => 'mail.example.net', 'user1' => 'alice',
            'password1' => 'pw', 'mins_interval' => 60, 'port1' => 143, 'enc1' => 'TLS', 'delete2duplicates' => 0,
            'custom_params' => '--maxsize=1000', 'active' => 0,
        ]), $id);
    }

    public function test_the_runner_selects_an_active_new_job_and_not_an_inactive_one(): void
    {
        $active = (int) $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org']))->json('data.id');
        $inactive = (int) $this->createSyncJob(self::syncJobBody(['mailbox' => 'alice@example.org', 'remoteUser' => 'b', 'active' => false]))->json('data.id');

        $selectable = (new ImapsyncRunnerSimulator)->selectableIds();
        $this->assertContains($active, $selectable);
        $this->assertNotContains($inactive, $selectable);
    }

    public function test_a_creation_changes_no_other_table_and_no_redis_key(): void
    {
        $counts = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), ['mailbox' => 'mailbox', 'alias' => 'alias', 'user_acl' => 'user_acl', 'sogo' => '_sogo_static_view', 'domain' => 'domain']);
        $headers = $this->csrf();
        // The API's own cache of mailcow's vars files (001) is not a side effect of the creation.
        $this->app->make(MailcowConfig::class)->imapsyncOptions();
        $before = $counts();
        $mailcowKeys = Redis::connection('mailcow')->command('dbsize');
        $apiKeys = Redis::connection('restv2')->command('keys', ['*']);

        $this->postJson('/api/v2/sync-jobs', self::syncJobBody(['mailbox' => 'alice@example.org']), $headers)->assertCreated();

        $this->assertSame($before, $counts());
        $this->assertSame($mailcowKeys, Redis::connection('mailcow')->command('dbsize'));
        $this->assertSame([], array_values(array_filter((array) Redis::connection('restv2')->command('keys', ['*']), fn (mixed $key): bool => ! in_array($key, (array) $apiKeys, true))));
        $this->assertSame(1, $this->syncJobCount());
    }
}
