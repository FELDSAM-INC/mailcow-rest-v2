<?php

namespace App\Services\Routing;

use App\Exceptions\ProblemException;
use App\Support\Database\LockNotAcquired;
use App\Support\Database\NamedLock;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The write lock of a routing table (feature 009 FR-083, FR-084, research R9): upstream indexes
 * none of the keys the spec makes unique (`transports.destination`, `bcc_maps` local destination
 * and type, `recipient_maps.old_dest`), so the check, the writes and the audit record run under
 * the table's MariaDB named lock (003's App\Support\Database\NamedLock) in one transaction. A lock
 * that another request holds longer than `restv2.routing.lock_timeout` seconds answers
 * `503 temporary-failure` (`dependency: database`) before anything runs.
 */
class RoutingLock
{
    public const TRANSPORTS = 'restv2:routing:transports';

    public const BCC_MAPS = 'restv2:routing:bcc_maps';

    public const RECIPIENT_MAPS = 'restv2:routing:recipient_maps';

    public function __construct(private readonly NamedLock $locks) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $code
     * @return T
     *
     * @throws ProblemException `temporary-failure` when the lock is not acquired in time
     */
    public function run(string $name, Closure $code): mixed
    {
        $timeout = max(1, (int) config('restv2.routing.lock_timeout', 10));

        try {
            $this->locks->acquire($name, $timeout);
        } catch (LockNotAcquired) {
            throw new ProblemException(
                ProblemType::TemporaryFailure,
                'Another change of the same routing entries is still in progress. Nothing was changed; retry later.',
                ['dependency' => 'database'],
                ['Retry-After' => '5'],
            );
        }

        try {
            return DB::transaction($code);
        } finally {
            $this->locks->release($name);
        }
    }
}
