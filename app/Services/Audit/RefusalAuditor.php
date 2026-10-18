<?php

namespace App\Services\Audit;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\CarriesAuditDetails;
use App\Http\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Refusal auditing (docs/conventions.md "Capabilities, history and refusals"; designed by feature
 * 008, created with feature 002). A route that declares
 * `->defaults('restv2.audit', ['event' => <AuditEventType value or 'fromBulkAction'>, 'target' =>
 * <target type>])` gets one failure record for every refusal of an authenticated caller, whatever
 * raised it: the request pipeline (read-only key, restricted session), the role check, the scope,
 * a conflict, an outdated version, a validation failure, or a temporary failure of mailcow's shared
 * store (`503 temporary-failure`). CSRF failures and requests without a principal (`401`) are never
 * recorded: a third-party site or an anonymous client could otherwise write audit rows at will.
 *
 * Features register a target resolver per target type (`resolveTargetsWith()`), which names the
 * object from the route and body, and may map their own problem types to a shared failure reason
 * (`mapProblemType()`). Details hold the problem type, the status and, for `422`, the field errors
 * with codes and parameters only: never request values.
 *
 * Bulk actions record their per-item refusals through record(). A service that writes its own
 * refusal record marks the request (markRecorded()) so that no second record is written.
 *
 * Feature 003: an exception that carries audit details (App\Exceptions\CarriesAuditDetails, for
 * example a rename's steps) adds them to the record, and such an exception is recorded also when it
 * ends in a `5xx` answer (`outcome-unknown`, `mailbox-rename-incomplete`, an unexpected failure
 * after the mail was moved).
 */
class RefusalAuditor
{
    private const RECORDED = 'restv2.refusalAudited';

    /** @var array<string, callable(Request, array<string, mixed>): array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}> */
    private array $resolvers = [];

    /** @var array<string, string> problem type URI => failure reason */
    private array $reasons = [
        '/api/v2/problems/forbidden' => 'permissionDenied',
        '/api/v2/problems/insufficient-key-scope' => 'permissionDenied',
        '/api/v2/problems/pending-action-required' => 'permissionDenied',
        '/api/v2/problems/permission-flag-required' => 'permissionDenied',
        '/api/v2/problems/session-required' => 'permissionDenied',
        '/api/v2/problems/attribute-not-permitted' => 'attributeNotPermitted',
        '/api/v2/problems/temporary-failure' => 'temporaryFailure',
        '/api/v2/problems/dkim-private-key-export-disabled' => 'featureDisabled',
        '/api/v2/problems/dkim-dns-publication-disabled' => 'featureDisabled',
        '/api/v2/problems/outcome-unknown' => 'outcomeUnknown',
        '/api/v2/problems/mailbox-rename-incomplete' => 'partiallyCompleted',
    ];

    public function __construct(private readonly AuditLogger $logger) {}

    /**
     * @param  callable(Request, array<string, mixed>): array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}  $resolver
     */
    public function resolveTargetsWith(string $targetType, callable $resolver): void
    {
        $this->resolvers[$targetType] = $resolver;
    }

    public function mapProblemType(string $problemTypeUri, string $failureReason): void
    {
        $this->reasons[$problemTypeUri] = $failureReason;
    }

    /**
     * Marks the request's refusal as already audited by the service that refused it.
     */
    public static function markRecorded(Request $request): void
    {
        $request->attributes->set(self::RECORDED, true);
    }

    /**
     * The hook of the problem renderer (bootstrap/app.php): records the refusal that the response
     * carries, when the route declares `restv2.audit`. Never throws.
     */
    public function recordResponse(Request $request, Response $response, ?Throwable $exception = null): void
    {
        try {
            $route = $request->route();
            $config = $route instanceof Route ? ($route->defaults['restv2.audit'] ?? null) : null;
            $principal = RequestContext::principal($request);

            if (! is_array($config) || $principal === null || $request->attributes->get(self::RECORDED) === true) {
                return;
            }

            $status = $response->getStatusCode();
            $body = json_decode((string) $response->getContent(), true);
            $type = is_array($body) && is_string($body['type'] ?? null) ? $body['type'] : 'about:blank';

            $carried = $exception instanceof CarriesAuditDetails ? $exception->auditDetails() : [];

            if (! $this->isRecordedRefusal($status, $type) && ! ($exception instanceof CarriesAuditDetails && $status >= 500)) {
                return;
            }

            $resolver = $this->resolvers[(string) ($config['target'] ?? '')] ?? null;
            $resolved = $resolver === null ? [] : $resolver($request, $config);
            $event = $resolved['event'] ?? AuditEventType::tryFrom((string) ($config['event'] ?? ''));

            if ($event === null) {
                return;
            }

            $this->record(
                $event,
                $principal,
                $resolved['target'] ?? null,
                $status,
                $type,
                is_array($body) && is_array($body['errors'] ?? null) ? $body['errors'] : null,
                $carried + ($resolved['details'] ?? []),
            );
            self::markRecorded($request);
        } catch (Throwable $e) {
            Log::error('A refusal could not be audited: '.$e->getMessage());
        }
    }

    /**
     * Writes one failure record, in its own transaction (the refused change was rolled back).
     *
     * @param  array{type: string, id?: string|null, name?: string|null}|null  $target
     * @param  array<string, mixed>|null  $errors  the problem's `errors` member
     * @param  array<string, mixed>  $details  further whitelisted details (for example `bulkRequestId`)
     */
    public function record(AuditEventType $event, Principal $actor, ?array $target, int $status, string $problemType, ?array $errors = null, array $details = []): void
    {
        $details = ['problemType' => $problemType, 'status' => $status] + $details;

        if ($status === 422 && $errors !== null) {
            $details['errors'] = self::codesOnly($errors);
        }

        $this->logger->record($event, false, $actor, $target, null, $this->reasonFor($status, $problemType), $details);
    }

    public function reasonFor(int $status, string $problemType): ?string
    {
        return $this->reasons[$problemType] ?? match ($status) {
            400, 422 => 'validationFailed',
            404 => 'notFound',
            409 => 'conflict',
            412 => 'versionOutdated',
            429 => 'throttled',
            403 => 'permissionDenied',
            default => null,
        };
    }

    private function isRecordedRefusal(int $status, string $type): bool
    {
        if ($status === 401 || $type === '/api/v2/problems/csrf-token-mismatch') {
            return false;
        }

        return ($status >= 400 && $status < 500 && $status !== 405) || $type === '/api/v2/problems/temporary-failure';
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return array<string, list<array{code: string, params: mixed}>>
     */
    private static function codesOnly(array $errors): array
    {
        $result = [];

        foreach ($errors as $field => $violations) {
            foreach (is_array($violations) ? $violations : [] as $violation) {
                if (is_array($violation) && isset($violation['code'])) {
                    $result[(string) $field][] = ['code' => (string) $violation['code'], 'params' => $violation['params'] ?? []];
                }
            }
        }

        return $result;
    }
}
