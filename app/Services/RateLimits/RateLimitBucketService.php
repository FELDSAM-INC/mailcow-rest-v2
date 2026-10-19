<?php

namespace App\Services\RateLimits;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\SharedStore\SharedStore;
use App\Policies\RateLimitPolicy;
use App\Services\Audit\AuditLogger;
use App\Support\Redis\LuaScripts;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Reset of a rate-limit bucket (feature 006 research R13, FR-057; legacy ratelimit('delete'), the
 * dashboard's "Remove hash"): one Lua script deletes the spam filter's counters (the bucket's
 * hash) and removes the rate-limited log entries whose bucket identifier (`rl_hash`) is the
 * bucket, atomically against the filter's pushes to `RL_LOG`. Legacy matches the identifier
 * anywhere in a log line, case-insensitively; matching the decoded `rl_hash` removes only the
 * bucket's own entries (spec deviation). "Not found" only when neither counters nor log entries
 * existed. The audit record carries the script's counts, so it is written right after the script;
 * when it cannot be written the answer is `500` (the reset, like a release's delivery, cannot be
 * taken back).
 */
class RateLimitBucketService
{
    public const LOG = 'RL_LOG';

    public function __construct(
        private readonly RateLimitPolicy $policy,
        private readonly SharedStore $store,
        private readonly AuditLogger $audit,
    ) {}

    public function reset(Principal $principal, string $bucketId): void
    {
        $this->policy->authorize($principal, RateLimitPolicy::RESET_BUCKET);

        DB::transaction(function () use ($principal, $bucketId): void {
            $reply = $this->store->evaluate(LuaScripts::RESET_RATE_LIMIT_BUCKET, [$bucketId, self::LOG]);
            $countersExisted = is_array($reply) && (int) ($reply[0] ?? 0) === 1;
            $removed = is_array($reply) ? (int) ($reply[1] ?? 0) : 0;

            if (! $countersExisted && $removed === 0) {
                throw new NotFoundHttpException('Neither counters nor log entries of the bucket exist.');
            }

            $this->audit->record(AuditEventType::RateLimitBucketReset, true, $principal, ['type' => 'rateLimitBucket', 'id' => $bucketId, 'name' => $bucketId], null, null, [
                'countersExisted' => $countersExisted,
                'logEntriesRemoved' => $removed,
            ]);
        });
    }
}
