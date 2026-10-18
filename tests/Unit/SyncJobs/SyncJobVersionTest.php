<?php

namespace Tests\Unit\SyncJobs;

use App\Services\SyncJobs\SyncJobVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Feature 008 T009 (research R4, FR-020): the version tag of a sync job is a strong ETag over its
 * stored configuration (23 columns, the remote password only as an HMAC keyed with the app key). The
 * runner's status columns, the times and the unexposed columns never change it.
 */
class SyncJobVersionTest extends TestCase
{
    /** @var array<string, mixed> */
    private const ROW = [
        'id' => 17, 'user2' => 'alice@example.org', 'host1' => 'imap.example.net', 'authmech1' => 'PLAIN',
        'regextrans2' => '', 'authmd51' => 0, 'domain2' => '', 'subfolder2' => '', 'user1' => 'alice',
        'password1' => 'remote-secret', 'exclude' => '(?i)spam|(?i)junk', 'maxage' => 0, 'mins_interval' => 20,
        'maxbytespersecond' => '0', 'port1' => 993, 'enc1' => 'SSL', 'delete2duplicates' => 1, 'delete1' => 0,
        'delete2' => 0, 'automap' => 1, 'skipcrossduplicates' => 0, 'custom_params' => '', 'timeout1' => 600,
        'timeout2' => 600, 'subscribeall' => 1, 'dry' => 0, 'is_running' => 0, 'returned_text' => null,
        'last_run' => null, 'success' => null, 'exit_status' => null, 'created' => '2026-10-18 10:00:00',
        'modified' => null, 'active' => 1,
    ];

    /**
     * @param  array<string, mixed>  $changes
     */
    private static function tag(array $changes = []): string
    {
        return (new SyncJobVersion)->forRow((object) array_merge(self::ROW, $changes));
    }

    public function test_the_tag_is_a_quoted_strong_etag(): void
    {
        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]{43}"$/', self::tag());
        $this->assertSame(self::tag(), self::tag());
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function configuration(): array
    {
        return [
            'id' => ['id', 18], 'user2' => ['user2', 'bob@example.org'], 'host1' => ['host1', 'imap.example.com'],
            'port1' => ['port1', 143], 'enc1' => ['enc1', 'TLS'], 'user1' => ['user1', 'bob'],
            'password1' => ['password1', 'other-secret'], 'exclude' => ['exclude', ''], 'subfolder2' => ['subfolder2', 'Imported'],
            'mins_interval' => ['mins_interval', 30], 'maxage' => ['maxage', 7], 'maxbytespersecond' => ['maxbytespersecond', '1000'],
            'timeout1' => ['timeout1', 60], 'timeout2' => ['timeout2', 60], 'delete1' => ['delete1', 1], 'delete2' => ['delete2', 1],
            'delete2duplicates' => ['delete2duplicates', 0], 'automap' => ['automap', 0], 'skipcrossduplicates' => ['skipcrossduplicates', 1],
            'subscribeall' => ['subscribeall', 0], 'dry' => ['dry', 1], 'custom_params' => ['custom_params', '--maxsize=1'],
            'active' => ['active', 0],
        ];
    }

    #[DataProvider('configuration')]
    public function test_every_configuration_column_changes_the_tag(string $column, mixed $value): void
    {
        $this->assertNotSame(self::tag(), self::tag([$column => $value]));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function statusAndUnexposed(): array
    {
        return [
            'is_running' => ['is_running', 1], 'last_run' => ['last_run', '2026-10-18 11:00:00'], 'success' => ['success', 1],
            'exit_status' => ['exit_status', 'EX_OK'], 'returned_text' => ['returned_text', 'log'], 'modified' => ['modified', '2026-10-18 12:00:00'],
            'created' => ['created', '2020-01-01 00:00:00'], 'authmech1' => ['authmech1', 'LOGIN'], 'regextrans2' => ['regextrans2', 's/a/b/'],
            'domain2' => ['domain2', 'example.org'], 'authmd51' => ['authmd51', 1],
        ];
    }

    #[DataProvider('statusAndUnexposed')]
    public function test_status_times_and_unexposed_columns_never_change_the_tag(string $column, mixed $value): void
    {
        $this->assertSame(self::tag(), self::tag([$column => $value]));
    }

    public function test_stored_number_formats_give_the_same_tag(): void
    {
        $this->assertSame(self::tag(['maxbytespersecond' => '0']), self::tag(['maxbytespersecond' => 0]));
        $this->assertSame(self::tag(['port1' => '993', 'active' => '1']), self::tag(['port1' => 993, 'active' => 1]));
    }

    public function test_the_password_enters_only_as_an_hmac_keyed_with_the_app_key(): void
    {
        $input = json_encode((new SyncJobVersion)->input((object) self::ROW), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('remote-secret', $input);
        $this->assertStringNotContainsString(hash('sha256', 'remote-secret'), $input);
        $this->assertStringContainsString(hash_hmac('sha256', 'remote-secret', (string) config('app.key')), $input);
    }

    public function test_another_app_key_gives_another_tag(): void
    {
        $before = self::tag();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertNotSame($before, self::tag());
    }
}
