<?php

namespace Tests\Feature\Security;

use App\Models\AuditEvent;
use App\Support\Http\Base64Url;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T064 (FR-018, SC-001, SC-007): every sync-job operation runs with a known remote
 * password, including refusals that name members and an unexpected failure. The password never
 * appears in a response body or header, a log line, an audit record or a rendered exception, and
 * no version tag is a plain hash of it.
 */
class SyncJobSecretLeakTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    private const SECRET = 'Remote-S3cret!';

    private TestHandler $logs;

    /** @var list<string> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $channel = Log::channel('stderr_json');
        $this->assertInstanceOf(\Illuminate\Log\Logger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->logs = new TestHandler);
        $this->syncMailbox('alice@example.org');
    }

    /**
     * Keeps a response's body and headers for the scan.
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    private function keep(TestResponse $response): TestResponse
    {
        $this->responses[] = (string) $response->getContent().json_encode($response->headers->all());

        return $response;
    }

    public function test_the_remote_password_never_leaves_the_database(): void
    {
        $this->asAdmin();
        $body = self::syncJobBody(['mailbox' => 'alice@example.org', 'remotePassword' => self::SECRET]);
        $id = (int) $this->keep($this->createSyncJob($body))->assertCreated()->json('data.id');

        $this->keep($this->createSyncJob($body))->assertStatus(409);
        $this->keep($this->createSyncJob(['remotePassword' => self::SECRET, 'remoteHost' => self::SECRET, 'remoteUser' => self::SECRET, 'remotePort' => 993, 'mailbox' => 'alice@example.org', 'exclude' => self::SECRET.'(', 'customParameters' => '--'.self::SECRET]))->assertUnprocessable();
        $this->keep($this->createSyncJob(array_merge($body, ['mailbox' => 'nobody@example.org'])))->assertNotFound();
        $this->keep($this->getJson('/api/v2/sync-jobs'))->assertOk();
        $this->keep($this->getJson('/api/v2/sync-jobs/'.$id))->assertOk();
        $this->keep($this->getJson('/api/v2/sync-jobs/'.$id.'/log'))->assertOk();
        $this->keep($this->getJson('/api/v2/capabilities/sync-jobs'))->assertOk();
        $this->keep($this->patchSyncJob($id, ['remotePassword' => self::SECRET.'2', 'intervalMinutes' => 30]))->assertOk();
        $this->keep($this->patchSyncJob($id, ['remotePassword' => self::SECRET, 'customParameters' => '--'.self::SECRET]))->assertUnprocessable();
        $this->keep($this->patchSyncJob($id, ['remotePassword' => self::SECRET], ['If-Match' => '"outdated"']))->assertStatus(412);
        $this->keep($this->rescheduleSyncJob($id))->assertOk();
        $this->keep($this->syncJobBulk('deactivate', [$id]))->assertOk();

        // An unexpected failure while the request carries the password.
        AuditEvent::creating(function (AuditEvent $event): void {
            if ($event->event_type === 'syncJobUpdate' && $event->outcome === 'success') {
                throw new RuntimeException('audit store unavailable');
            }
        });
        $this->keep($this->patchSyncJob($id, ['remotePassword' => self::SECRET.'3']))->assertStatus(500);

        $this->keep($this->deleteSyncJob($id))->assertNoContent();
        $this->forgetCookies();
        $this->mailcow()->admin('admin2', 'Passw0rd!', 'SSHA256');
        $this->keep($this->createSyncJob($body, $this->keyHeader($this->apiKey('admin2', 'readOnly'))))->assertForbidden();

        $scanned = [
            'responses' => implode("\n", $this->responses),
            'logs' => implode("\n", array_map(fn ($record): string => json_encode($record->toArray()) ?: '', $this->logs->getRecords())),
            'audit' => DB::table('restv2_audit_events')->get()->toJson(),
        ];

        foreach ($scanned as $where => $text) {
            $this->assertStringNotContainsString('Remote-S3cret', $text, "The remote password appears in the {$where}.");
        }

        $hash = hash('sha256', self::SECRET, true);
        foreach ([bin2hex($hash), base64_encode($hash), Base64Url::encode($hash)] as $encoding) {
            $this->assertStringNotContainsString($encoding, $scanned['responses']);
        }
    }
}
