<?php

namespace Tests\Feature\Domains;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T050 (FR-024, FR-056): the domain row, its tags and its domain map entry are stored
 * together or not at all; an unavailable shared store refuses the creation with a temporary
 * failure; of two concurrent creations of one name, exactly one succeeds.
 */
class CreateDomainAtomicityTest extends TestCase
{
    use DomainTestHelpers;

    public function test_an_unavailable_shared_store_refuses_the_creation(): void
    {
        $this->asAdmin();
        $csrf = $this->csrf();
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $this->postJson('/api/v2/domains', ['name' => 'example.org', 'tags' => ['a']], $csrf)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '10')
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure');

        $this->assertNull($this->domainRow('example.org'));
        $this->assertSame([], $this->tagsOf('example.org'));
        $record = $this->auditOf('domainCreation')[0] ?? null;
        $this->assertNotNull($record);
        $this->assertSame('failure', $record->outcome);
        $this->assertSame('temporaryFailure', $record->failure_reason);
        $this->assertSame('example.org', $record->target_id);
    }

    public function test_a_failing_domain_map_write_rolls_the_rows_back(): void
    {
        $this->app->instance(SharedStore::class, new class($this->app->make(RedisFactory::class)) extends SharedStore
        {
            public function setFields(string $key, array $fields): Closure
            {
                throw new SharedStoreUnavailable('connection lost');
            }
        });
        $this->asAdmin();

        $this->postJson('/api/v2/domains', ['name' => 'example.org', 'tags' => ['a']], $this->csrf())->assertStatus(503);

        $this->assertNull($this->domainRow('example.org'));
        $this->assertSame([], $this->tagsOf('example.org'));
    }

    public function test_a_failure_after_the_domain_map_write_removes_the_entry_again(): void
    {
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditEventType $type, bool $success, Principal|array|null $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): AuditEvent
            {
                if ($type === AuditEventType::DomainCreation && $success) {
                    throw new RuntimeException('the audit table is full');
                }

                return parent::record($type, $success, $actor, $target, $authMethod, $failureReason, $details);
            }
        });
        $this->asAdmin();

        $this->postJson('/api/v2/domains', ['name' => 'example.org', 'dkimSelector' => ''], $this->csrf())->assertStatus(500);

        $this->assertNull($this->domainRow('example.org'));
        $this->assertFalse($this->redis()->hGet('DOMAIN_MAP', 'example.org'));
    }

    /**
     * Two processes create the same name at the same moment, outside the test's transaction; the
     * result is checked through a separate connection and cleaned up afterwards.
     */
    public function test_of_two_concurrent_creations_exactly_one_succeeds(): void
    {
        config(['database.connections.observer' => config('database.connections.mysql')]);
        $observer = DB::connection('observer');
        $name = 'race-'.bin2hex(random_bytes(4)).'.example';
        $start = microtime(true) + 4.0;
        $code = sprintf(
            '$p = new App\Auth\Principal("admin", App\Auth\Role::Admin, App\Auth\AccountKind::Admin, App\Auth\AuthMethod::Password);'
            .'while (microtime(true) < %F) { usleep(500); }'
            .'try { app(App\Services\Domains\DomainCreator::class)->create($p, ["name" => "%s", "dkimSelector" => ""]); echo "RESULT:created"; }'
            .' catch (App\Exceptions\ProblemException $e) { echo "RESULT:".$e->type->value; }',
            $start,
            $name,
        );
        $php = (new PhpExecutableFinder)->find(false) ?: 'php';
        $processes = [];

        try {
            foreach ([1, 2] as $i) {
                $processes[$i] = new Process([$php, 'artisan', 'tinker', '--env=testing', '--execute='.$code], base_path(), ['APP_ENV' => 'testing']);
                $processes[$i]->setTimeout(60);
                $processes[$i]->start();
            }

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                preg_match('/RESULT:(\S+)/', $process->getOutput(), $m);
                $results[] = $m[1] ?? 'error: '.$process->getErrorOutput().$process->getOutput();
            }

            sort($results);
            $this->assertSame(['created', 'domain-exists'], $results);
            $this->assertSame(1, $observer->table('domain')->where('domain', $name)->count());
        } finally {
            $observer->table('domain')->where('domain', $name)->delete();
            $observer->table('restv2_audit_events')->where('target_id', $name)->delete();
            Redis::connection('mailcow')->hdel('DOMAIN_MAP', $name);
        }
    }

    public function test_the_role_is_checked_before_anything_is_stored(): void
    {
        $this->mailcow()->domain('mine.example');
        $this->asDomainAdmin(['mine.example']);

        $this->postJson('/api/v2/domains', ['name' => 'example.org'], $this->csrf())->assertStatus(403);

        $this->assertNull($this->domainRow('example.org'));
    }
}
