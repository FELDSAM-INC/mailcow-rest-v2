<?php

namespace App\Services\Health;

use App\Models\Mailcow\SchemaVersion;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * The readiness checks (constitution runtime, R24), shared by `GET /health/ready` and
 * `restv2:health --ready`:
 *
 *  - `database`: `SELECT 1` on the default connection;
 *  - `schema`: mailcow's `versions.db_schema` is one this release supports;
 *  - `redis`: `PING` on the API's Redis connection;
 *  - `migrations`: every `restv2_` migration is applied.
 *
 * A check that needs the database fails when the database does.
 */
class ReadinessChecker
{
    public function __construct(private readonly Migrator $migrator) {}

    /**
     * @return array{database: string, schema: string, redis: string, migrations: string}
     */
    public function check(): array
    {
        $database = $this->passes(fn (): bool => DB::connection()->select('SELECT 1 AS ok') !== []);

        return [
            'database' => self::status($database),
            'schema' => self::status($database && $this->passes(
                fn (): bool => in_array(SchemaVersion::current(), (array) config('restv2.supported_schema_versions'), true),
            )),
            'redis' => self::status($this->passes(fn (): bool => Redis::connection('restv2')->command('ping') !== false)),
            'migrations' => self::status($database && $this->passes(fn (): bool => $this->pendingMigrations() === [])),
        ];
    }

    /**
     * @param  array<string, string>  $checks
     */
    public static function ready(array $checks): bool
    {
        return ! in_array('failed', $checks, true);
    }

    /**
     * Migration files that are not applied yet.
     *
     * @return list<string>
     */
    private function pendingMigrations(): array
    {
        if (! $this->migrator->repositoryExists()) {
            return ['(migrations table missing)'];
        }

        $files = $this->migrator->getMigrationFiles(array_merge([database_path('migrations')], $this->migrator->paths()));

        return array_values(array_diff(array_keys($files), $this->migrator->getRepository()->getRan()));
    }

    /**
     * @param  callable(): bool  $check
     */
    private function passes(callable $check): bool
    {
        try {
            return $check();
        } catch (Throwable) {
            return false;
        }
    }

    private static function status(bool $ok): string
    {
        return $ok ? 'ok' : 'failed';
    }
}
