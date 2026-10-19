<?php

namespace App\Services\Quarantine;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Http\RequestContext;
use App\Support\Bulk\BulkActionRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Quarantine bulk actions (feature 006 research R18, FR-027), built like 002's DomainBulkActions
 * on the shared BulkActionRunner: the whole request was authorised already (role, flags, key
 * scope); the targets are deduplicated by item and each distinct item is processed by
 * QuarantineActionService with its `version` as `If-Match`, so it gets the single operation's
 * checks, side effects, lock and audit record (with `bulkRequestId`). A `done` release or learning
 * as spam carries its action report; refusals map to `notFound`, `refused` (busy item, refused
 * delivery), `outdated` and `failed` (temporary failures), and are recorded per item.
 */
class QuarantineBulkActions
{
    public function __construct(
        private readonly BulkActionRunner $runner,
        private readonly QuarantineActionService $actions,
        private readonly QuarantineQuery $query,
    ) {}

    /**
     * @param  list<array{id: string, version: string|null}>  $targets
     * @return array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function apply(Request $request, Principal $principal, string $action, array $targets): array
    {
        $bulkRequestId = RequestContext::requestId($request);

        return $this->runner->run(
            $request,
            $principal,
            self::event($action),
            $targets,
            fn (string $id): ?string => ctype_digit($id) && (int) $id > 0 && $this->query->exists($principal, (int) $id) ? (string) (int) $id : null,
            /** @return array{report: array<string, mixed>}|null */
            function (string $key, ?string $ifMatch) use ($principal, $action, $bulkRequestId): ?array {
                $id = (int) $key;

                return match ($action) {
                    'release' => ['report' => $this->actions->release($principal, $id, true, $ifMatch, $bulkRequestId)->toArray()],
                    'releaseWithoutLearning' => ['report' => $this->actions->release($principal, $id, false, $ifMatch, $bulkRequestId)->toArray()],
                    'learnAsSpam' => ['report' => $this->actions->learnAsSpam($principal, $id, $ifMatch, $bulkRequestId)->toArray()],
                    default => $this->delete($principal, $id, $ifMatch, $bulkRequestId),
                };
            },
            function (string $id, ?string $key): array {
                $row = $key === null ? null : DB::table('quarantine')->where('id', (int) $key)->first(['qid']);

                return ['type' => 'quarantineItem', 'id' => $key ?? mb_substr($id, 0, 255), 'name' => $row === null ? null : (string) $row->qid];
            },
        );
    }

    public static function event(string $action): AuditEventType
    {
        return match ($action) {
            'learnAsSpam' => AuditEventType::QuarantineItemSpamLearning,
            'delete' => AuditEventType::QuarantineItemDeletion,
            default => AuditEventType::QuarantineItemRelease,
        };
    }

    private function delete(Principal $principal, int $id, ?string $ifMatch, string $bulkRequestId): null
    {
        $this->actions->delete($principal, $id, $ifMatch, $bulkRequestId);

        return null;
    }
}
