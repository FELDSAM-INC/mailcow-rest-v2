<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditEventType;
use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\IrreversibleOperationAudit;
use App\Support\Clock\Deadline;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\TestCase;

/**
 * Feature 011 T013 (research R24, FR-064): irreversible operations probe the audit log before they
 * start and write their record afterwards with retries until DOCKER_TIMEOUT; a record that still
 * cannot be written is logged and reported with `Audit-Recorded: false`.
 */
class IrreversibleOperationAuditTest extends TestCase
{
    private function admin(): Principal
    {
        return new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::Password);
    }

    private function audit(): IrreversibleOperationAudit
    {
        return $this->app->make(IrreversibleOperationAudit::class);
    }

    /**
     * @param  list<int>  $failingAttempts  1-based attempt numbers that fail
     */
    private function failingLogger(array $failingAttempts, bool $insertBeforeFailing = false): FlakyAuditLogger
    {
        $logger = new FlakyAuditLogger($failingAttempts, $insertBeforeFailing);
        $this->app->instance(AuditLogger::class, $logger);

        return $logger;
    }

    private function rows(): int
    {
        return DB::table('restv2_audit_events')->where('event_type', 'containerRestart')->count();
    }

    public function test_the_probe_passes_while_the_audit_log_can_be_read(): void
    {
        $this->audit()->probe();

        $this->addToAssertionCount(1);
    }

    public function test_the_probe_refuses_with_a_temporary_failure_when_the_audit_log_cannot_be_read(): void
    {
        $default = config('database.default');
        config(['database.connections.broken' => ['host' => '127.0.0.1', 'port' => 1] + (array) config('database.connections.mysql'), 'database.default' => 'broken']);

        try {
            $this->audit()->probe();
            $this->fail('the probe passed');
        } catch (ProblemException $e) {
            $this->assertSame(ProblemType::TemporaryFailure, $e->type);
            $this->assertArrayHasKey('Retry-After', $e->headers);
        } finally {
            config(['database.default' => $default]);
            DB::purge('broken');
        }
    }

    public function test_a_record_is_inserted_once(): void
    {
        $recorded = $this->audit()->recordWithRetry(AuditEventType::ContainerRestart, true, $this->admin(), ['type' => 'container', 'id' => 'sogo-mailcow', 'name' => 'sogo-mailcow'], null, ['outcome' => 'done'], new Deadline($this->clock(), 60));

        $this->assertTrue($recorded);
        $this->assertSame(1, $this->rows());
        $this->assertSame([], $this->sleeper()->slept);
    }

    public function test_a_failed_insert_is_retried_every_second(): void
    {
        $logger = $this->failingLogger([1, 2]);
        $this->sleeper()->advancing($this->clock());

        $recorded = $this->audit()->recordWithRetry(AuditEventType::ContainerRestart, true, $this->admin(), ['type' => 'container', 'id' => 'mysql-mailcow', 'name' => 'mysql-mailcow'], null, [], new Deadline($this->clock(), 60));

        $this->assertTrue($recorded);
        $this->assertSame(3, $logger->attempts);
        $this->assertSame([1.0, 1.0], $this->sleeper()->slept);
        $this->assertSame(1, $this->rows());
    }

    public function test_a_duplicate_id_on_retry_counts_as_recorded(): void
    {
        $logger = $this->failingLogger([1], insertBeforeFailing: true);
        $this->sleeper()->advancing($this->clock());

        $recorded = $this->audit()->recordWithRetry(AuditEventType::ContainerRestart, true, $this->admin(), ['type' => 'container', 'id' => 'mysql-mailcow', 'name' => 'mysql-mailcow'], null, [], new Deadline($this->clock(), 60));

        $this->assertTrue($recorded);
        $this->assertSame(2, $logger->attempts);
        $this->assertSame(1, $this->rows(), 'the retry wrote a second record');
    }

    public function test_after_the_deadline_the_failure_is_logged_and_reported(): void
    {
        $this->failingLogger(range(1, 100));
        $this->sleeper()->advancing($this->clock());
        Log::spy();

        $recorded = $this->audit()->recordWithRetry(AuditEventType::ContainerRestart, true, $this->admin(), ['type' => 'container', 'id' => 'mysql-mailcow', 'name' => 'mysql-mailcow'], null, ['stateBefore' => 'running'], new Deadline($this->clock(), 5));

        $this->assertFalse($recorded);
        $this->assertSame(0, $this->rows());
        $this->assertLessThanOrEqual(6, count($this->sleeper()->slept));
        Log::getFacadeRoot()->shouldHaveReceived('error')->withArgs(function (string $message, array $context = []): bool {
            return str_contains($message, 'containerRestart') && ($context['target'] ?? null) === 'container mysql-mailcow';
        })->once();
    }

    public function test_the_response_macro_adds_audit_recorded_false(): void
    {
        $this->assertSame('false', response()->json(['data' => []])->withAuditRecorded(false)->headers->get('Audit-Recorded'));
        $this->assertNull(response()->json(['data' => []])->withAuditRecorded(true)->headers->get('Audit-Recorded'));
    }

    public function test_the_deadline_is_docker_timeout(): void
    {
        config(['restv2.vars_overrides.DOCKER_TIMEOUT' => '42']);

        $this->assertSame(42.0, $this->audit()->deadline()->limitSeconds);
    }

    public function test_the_logger_keeps_a_given_id(): void
    {
        $id = (string) Str::ulid();

        $event = $this->app->make(AuditLogger::class)->recordWithId($id, AuditEventType::ContainerRestart, true, $this->admin(), null, null, null, []);

        $this->assertSame($id, $event->id);
    }
}
