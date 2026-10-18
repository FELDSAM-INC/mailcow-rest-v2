<?php

namespace Tests\Unit\Architecture;

use App\Models\Mailcow\LegacyUiLogEntry;
use App\Models\Mailcow\MailcowModel;
use PHPUnit\Framework\TestCase;

/**
 * Feature 011 T110 (constitution IV, FR-068): the feature's controllers never touch Redis, the
 * database or an outbound client directly (services own every change and its side effects), and
 * the legacy activity log is never written.
 */
class MonitoringWritesTest extends TestCase
{
    private const CONTROLLERS = [
        'HostOverview', 'MailStorageUsage', 'PublicAddresses', 'Installation', 'UpdateStatus', 'MonitoringCapabilities',
        'Container', 'ContainerResourceUsage', 'ContainerStart', 'ContainerStop', 'ContainerRestart', 'LogEntry',
        'RspamdHistory', 'RspamdStatistics', 'MailQueueMessage', 'MailQueueMessageContent', 'MailQueueBulkAction',
        'MailQueueFlush', 'MailQueuePurge', 'Fail2BanSettings', 'Fail2BanBanListRotation', 'Fail2BanRules',
        'Fail2BanRulesReset', 'Fail2BanActiveBan', 'Fail2BanBanLift', 'Fail2BanBanAllowlist', 'Fail2BanBanDenylist',
        'Fail2BanPermanentBan',
    ];

    /** Code fragments a controller of this feature must not contain. */
    private const FORBIDDEN = [
        'use App\Clients\DockerApi\DockerApiClient;' => 'the container service client',
        'use App\Clients\SharedStore\SharedStore;' => 'the shared store',
        'use App\Clients\Fail2Ban\Fail2BanStore;' => 'the ban service store',
        'use App\Clients\Rspamd\RspamdController;' => 'the Rspamd client',
        'use App\Clients\Releases\ReleaseSource;' => 'the release source',
        'use App\Clients\PublicAddress\PublicAddressLookup;' => 'the IP check client',
        'use Illuminate\Support\Facades\Redis;' => 'Redis',
        'use Illuminate\Support\Facades\DB;' => 'the database',
        'Redis::' => 'Redis',
        'DB::' => 'the database',
        '->execText(' => 'a container service call',
        '->exec(' => 'a container service call',
        '->restart(' => 'a container service call',
    ];

    public function test_controllers_use_services_only(): void
    {
        $violations = [];

        foreach (self::CONTROLLERS as $name) {
            $path = __DIR__."/../../../app/Http/Controllers/Api/V2/{$name}Controller.php";
            $this->assertFileExists($path);
            $code = (string) file_get_contents($path);

            foreach (self::FORBIDDEN as $fragment => $what) {
                if (str_contains($code, $fragment)) {
                    $violations[] = "{$name}Controller uses {$what}";
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_the_legacy_activity_log_model_refuses_writes(): void
    {
        $code = (string) file_get_contents(__DIR__.'/../../../app/Models/Mailcow/LegacyUiLogEntry.php');

        $this->assertStringContainsString('static::saving(', $code);
        $this->assertStringContainsString('static::deleting(', $code);
        $this->assertTrue(is_subclass_of(LegacyUiLogEntry::class, MailcowModel::class));
    }
}
