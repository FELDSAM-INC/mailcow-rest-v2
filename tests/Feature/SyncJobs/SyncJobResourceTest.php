<?php

namespace Tests\Feature\SyncJobs;

use App\Http\Resources\Api\V2\SyncJobResource;
use App\Models\Mailcow\SyncJob;
use App\Services\SyncJobs\SyncJobVersion;
use Illuminate\Support\Facades\DB;
use Tests\Support\Contract\OpenApiContract;
use Tests\TestCase;

/**
 * Feature 008 T012 (research R3, FR-007, FR-008, FR-010): a stored `imapsync` row is rendered as
 * the contract's `SyncJob`: camelCase members with units, the encryption and run status mapped,
 * mailcow's local times in UTC, and never the remote password or the log.
 */
class SyncJobResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxWithDomain('alice@example.org');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function render(array $overrides = [], bool $logAvailable = false): array
    {
        $id = $this->mailcow()->syncJob('alice@example.org', $overrides);
        $job = SyncJob::query()->findOrFail($id);
        $data = SyncJobResource::present($job, $logAvailable);

        $this->assertSame([], OpenApiContract::shared()->validateComponent($data, 'SyncJob'));

        return $data;
    }

    public function test_every_member_is_mapped_from_its_column(): void
    {
        $data = $this->render([
            'host1' => 'imap.example.net', 'port1' => 143, 'enc1' => 'TLS', 'user1' => 'alice.old',
            'exclude' => 'Trash', 'subfolder2' => 'Imported', 'mins_interval' => 30, 'maxage' => 7,
            'maxbytespersecond' => '50000', 'timeout1' => 60, 'timeout2' => 120, 'delete1' => 1, 'delete2' => 1,
            'delete2duplicates' => 0, 'automap' => 0, 'skipcrossduplicates' => 1, 'subscribeall' => 0, 'dry' => 1,
            'custom_params' => '--maxsize=1000', 'active' => 0, 'created' => '2026-10-18 12:00:00',
        ], logAvailable: true);

        $this->assertSame('alice@example.org', $data['mailbox']);
        $this->assertSame('imap.example.net', $data['remoteHost']);
        $this->assertSame(143, $data['remotePort']);
        $this->assertSame('starttls', $data['encryption']);
        $this->assertSame('plain', $data['authMechanism']);
        $this->assertSame('alice.old', $data['remoteUser']);
        $this->assertTrue($data['remotePasswordSet']);
        $this->assertSame('Trash', $data['exclude']);
        $this->assertSame('Imported', $data['destinationSubfolder']);
        $this->assertSame(30, $data['intervalMinutes']);
        $this->assertSame(7, $data['maxAgeDays']);
        $this->assertSame(50000, $data['maxBytesPerSecond']);
        $this->assertSame(60, $data['remoteTimeoutSeconds']);
        $this->assertSame(120, $data['localTimeoutSeconds']);
        $this->assertTrue($data['deleteFromSource']);
        $this->assertTrue($data['deleteFromDestination']);
        $this->assertFalse($data['deleteDuplicatesOnDestination']);
        $this->assertFalse($data['automap']);
        $this->assertTrue($data['skipCrossDuplicates']);
        $this->assertFalse($data['subscribeAll']);
        $this->assertTrue($data['dryRun']);
        $this->assertSame('--maxsize=1000', $data['customParameters']);
        $this->assertFalse($data['active']);
        $this->assertSame('2026-10-18T10:00:00Z', $data['createdAt']);
        $this->assertNull($data['modifiedAt']);
        $this->assertTrue($data['runStatus']['logAvailable']);
        $this->assertSame((new SyncJobVersion)->forRow(DB::table('imapsync')->where('id', $data['id'])->first() ?? (object) []), $data['etag']);
    }

    public function test_the_run_status_follows_the_runner_s_columns(): void
    {
        $never = $this->render();
        $this->assertSame(['lastRunAt' => null, 'lastResult' => 'unknown', 'exitStatus' => null, 'running' => false, 'logAvailable' => false], $never['runStatus']);

        $failed = $this->render(['user1' => 'f', 'last_run' => '2026-01-15 12:00:00', 'success' => 0, 'exit_status' => 'EXIT_AUTHENTICATION_FAILURE_USER1']);
        $this->assertSame('2026-01-15T11:00:00Z', $failed['runStatus']['lastRunAt']);
        $this->assertSame('failed', $failed['runStatus']['lastResult']);
        $this->assertSame('EXIT_AUTHENTICATION_FAILURE_USER1', $failed['runStatus']['exitStatus']);

        $cleared = $this->render(['user1' => 'c', 'success' => null, 'exit_status' => 'EX_OK', 'is_running' => 1]);
        $this->assertSame('unknown', $cleared['runStatus']['lastResult']);
        $this->assertNull($cleared['runStatus']['exitStatus']);
        $this->assertTrue($cleared['runStatus']['running']);

        $succeeded = $this->render(['user1' => 's', 'success' => 1, 'exit_status' => 'EX_OK']);
        $this->assertSame('succeeded', $succeeded['runStatus']['lastResult']);
        $this->assertSame('EX_OK', $succeeded['runStatus']['exitStatus']);
    }

    public function test_the_password_and_the_log_never_appear(): void
    {
        $data = $this->render(['password1' => '', 'returned_text' => 'secret log'], logAvailable: true);

        $this->assertFalse($data['remotePasswordSet']);
        $json = (string) json_encode($data);
        $this->assertStringNotContainsString('password1', $json);
        $this->assertStringNotContainsString('returned_text', $json);
        $this->assertStringNotContainsString('secret log', $json);
        $this->assertStringNotContainsString('remote-secret', (string) json_encode($this->render(['user1' => 'other'])));

        foreach (array_keys($data) as $key) {
            $this->assertStringNotContainsString('password', (string) $key);
        }
    }
}
