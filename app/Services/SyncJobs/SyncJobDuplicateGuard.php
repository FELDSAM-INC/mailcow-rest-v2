<?php

namespace App\Services\SyncJobs;

use App\Exceptions\ProblemException;
use App\Support\Database\NamedLock;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The uniqueness of `(user2, user1, host1)` that legacy enforces only in code (feature 008
 * FR-017, research R9; docs/conventions.md: a MariaDB named lock, never a new index on an upstream
 * table). Under 003's NamedLock `restv2_syncjob_<sha1>` the write runs in a transaction after a
 * duplicate check with legacy's SQL equality (the column collation: case-insensitive); a duplicate
 * answers `409 sync-job-exists` with its identifier. Taking the lock before the transaction lets
 * the check see a concurrent request's committed row.
 */
class SyncJobDuplicateGuard
{
    public function __construct(private readonly NamedLock $locks) {}

    public static function lockName(string $user2, string $user1, string $host1): string
    {
        return 'restv2_syncjob_'.substr(sha1(mb_strtolower($user2."\0".$user1."\0".$host1)), 0, 40);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public function run(string $user2, string $user1, string $host1, ?int $exceptId, Closure $write): mixed
    {
        return $this->locks->run([self::lockName($user2, $user1, $host1)], fn (): mixed => DB::transaction(function () use ($user2, $user1, $host1, $exceptId, $write): mixed {
            $duplicate = DB::table('imapsync')
                ->where('user2', $user2)
                ->where('user1', $user1)
                ->where('host1', $host1)
                ->when($exceptId !== null, fn ($query) => $query->where('id', '<>', $exceptId))
                ->value('id');

            if ($duplicate !== null) {
                throw new ProblemException(ProblemType::SyncJobExists, null, ['syncJobId' => (int) $duplicate]);
            }

            return $write();
        }));
    }
}
