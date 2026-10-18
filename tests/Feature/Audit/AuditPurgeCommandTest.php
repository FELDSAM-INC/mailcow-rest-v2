<?php

namespace Tests\Feature\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T098 (FR-048, R22): `restv2:audit-purge` removes the records older than the retention period in
 * batches, and no HTTP operation changes or deletes records.
 */
class AuditPurgeCommandTest extends TestCase
{
    /**
     * @param  int  $count  records at the given age
     */
    private function records(int $count, CarbonImmutable $occurredAt): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'occurred_at' => $occurredAt->format('Y-m-d H:i:s.u'),
                'event_type' => 'signIn',
                'outcome' => 'failure',
                'client_address' => '192.0.2.1',
                'request_id' => bin2hex(random_bytes(16)),
                'details' => '{}',
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('restv2_audit_events')->insert($chunk);
        }
    }

    public function test_records_older_than_the_retention_period_are_deleted_in_batches(): void
    {
        $this->freezeTime();
        config(['restv2.audit_retention_days' => 30]);
        $now = CarbonImmutable::now('UTC');
        $this->records(2500, $now->subDays(31));
        $this->records(1, $now->subDays(30)->subSecond());
        $this->records(3, $now->subDays(30)->addSecond());
        $this->records(2, $now->subDay());
        $deletes = 0;
        DB::listen(function (QueryExecuted $query) use (&$deletes): void {
            if (str_starts_with(strtolower($query->sql), 'delete')) {
                $deletes++;
            }
        });

        $this->artisan('restv2:audit-purge')
            ->expectsOutputToContain('Deleted 2501 audit records older than 30 days.')
            ->assertExitCode(0);

        $this->assertSame(5, DB::table('restv2_audit_events')->count());
        $this->assertSame(0, DB::table('restv2_audit_events')->where('occurred_at', '<', $now->subDays(30)->format('Y-m-d H:i:s.u'))->count());
        // Batches of 1,000: two full ones and the last, partial one.
        $this->assertSame(3, $deletes);
    }

    public function test_the_default_retention_is_a_year(): void
    {
        $this->freezeTime();
        $now = CarbonImmutable::now('UTC');
        $this->records(1, $now->subDays(366));
        $this->records(1, $now->subDays(364));

        $this->artisan('restv2:audit-purge')->expectsOutputToContain('Deleted 1 audit records older than 365 days.')->assertExitCode(0);

        $this->assertSame(1, DB::table('restv2_audit_events')->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writeMethods(): array
    {
        return ['PUT' => ['PUT'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    #[DataProvider('writeMethods')]
    public function test_no_http_operation_changes_records(string $method): void
    {
        $this->records(1, CarbonImmutable::now('UTC'));

        $this->json($method, '/api/v2/audit-events')
            ->assertStatus(405)
            ->assertHeader('Content-Type', 'application/problem+json');

        $this->assertSame(1, DB::table('restv2_audit_events')->count());
    }
}
