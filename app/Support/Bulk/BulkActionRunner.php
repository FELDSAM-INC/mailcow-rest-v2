<?php

namespace App\Support\Bulk;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\ProblemRenderer;
use App\Http\RequestContext;
use App\Services\Audit\RefusalAuditor;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The per-target loop of every bulk action (docs/conventions.md "Bulk actions"; created with
 * feature 002, reused by features 003 to 013): targets are resolved and deduplicated in request
 * order (the first spelling stays the result's `id`), each item's optional `version` is passed on
 * as its `If-Match` (with or without the quotes of the ETag header, G33), and each item is applied
 * on its own: one failure never stops the others.
 *
 * An item's exception is rendered as the problem the single operation would answer and mapped to
 * its outcome (`404` notFound, `412` outdated, other `4xx` refused, else failed); its refusal is
 * audited through RefusalAuditor with the request's `bulkRequestId`. Success records are the
 * services' own. The result is the contract's `BulkResult`.
 */
class BulkActionRunner
{
    public function __construct(private readonly RefusalAuditor $refusals) {}

    /**
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @param  callable(string): ?string  $resolve  the object's key, or null when it does not exist for the caller
     * @param  callable(string, ?string): ?string  $apply  applies the action to a key with an If-Match value; returns the new version, or null when the object is gone
     * @param  callable(string, ?string): ?array{type: string, id?: string|null, name?: string|null}  $auditTarget  the audit target of an item (raw ID, key)
     * @return array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function run(Request $request, Principal $actor, AuditEventType $event, array $targets, callable $resolve, callable $apply, callable $auditTarget): array
    {
        $bulkRequestId = RequestContext::requestId($request);
        $results = [];
        $seen = [];
        $summary = ['total' => 0, 'done' => 0, 'notFound' => 0, 'refused' => 0, 'outdated' => 0, 'failed' => 0];

        foreach ($targets as $target) {
            $id = $target['id'];
            $key = $resolve($id);
            $dedupe = $key ?? "\0".$id;

            if (isset($seen[$dedupe])) {
                continue;
            }

            $seen[$dedupe] = true;
            $result = ['id' => $id];

            try {
                if ($key === null) {
                    throw new NotFoundHttpException('The object does not exist.');
                }

                $version = $apply($key, self::ifMatch($target['version'] ?? null));
                $result['outcome'] = BulkOutcome::Done->value;

                if ($version !== null) {
                    $result['version'] = $version;
                }
            } catch (Throwable $e) {
                $response = ProblemRenderer::render($e, $request);
                $problem = (array) json_decode((string) $response->getContent(), true);
                $outcome = BulkOutcome::fromStatus($response->getStatusCode());
                $result['outcome'] = $outcome->value;

                if ($outcome !== BulkOutcome::NotFound) {
                    $result['problem'] = $problem;
                }

                if ($response->getStatusCode() >= 500 && ($problem['type'] ?? null) !== '/api/v2/problems/temporary-failure') {
                    report($e);
                }

                $this->refusals->record(
                    $event,
                    $actor,
                    $auditTarget($id, $key),
                    $response->getStatusCode(),
                    (string) ($problem['type'] ?? 'about:blank'),
                    is_array($problem['errors'] ?? null) ? $problem['errors'] : null,
                    ['bulkRequestId' => $bulkRequestId],
                );
            }

            $summary['total']++;
            $summary[$result['outcome']]++;
            $results[] = $result;
        }

        return ['bulkRequestId' => $bulkRequestId, 'results' => $results, 'summary' => $summary];
    }

    /**
     * A bulk item's version as an `If-Match` value: the exact ETag (quoted), or the tag without
     * its quotes (G33); null when the item carries none.
     */
    public static function ifMatch(?string $version): ?string
    {
        if ($version === null || trim($version) === '') {
            return null;
        }

        $version = trim($version);

        return str_starts_with($version, '"') || str_starts_with($version, 'W/"') ? $version : '"'.$version.'"';
    }
}
