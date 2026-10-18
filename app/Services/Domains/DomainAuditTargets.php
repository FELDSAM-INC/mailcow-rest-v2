<?php

namespace App\Services\Domains;

use App\Audit\AuditEventType;
use App\Services\Audit\RefusalAuditor;
use App\Support\Domain\DomainName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Target resolvers of feature 002 for the shared refusal auditing: `domain` (the path name or the
 * created name), `domainFooter` and `domainTemplate` (the path ID or the created name). A refused
 * bulk request is recorded once with a null target, the requested `targetIds` and the action;
 * its event follows the action. Names are normalised when they can be, otherwise kept as sent,
 * cut to 255 characters. The audit log is for administrators, so names outside the caller's scope
 * may be recorded.
 */
final class DomainAuditTargets
{
    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('domain', self::domain(...));
        $auditor->resolveTargetsWith('domainFooter', self::footer(...));
        $auditor->resolveTargetsWith('domainTemplate', self::template(...));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function domain(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            $action = $request->input('action');
            $targets = $request->input('targets');
            $ids = [];

            foreach (is_array($targets) ? array_slice($targets, 0, 100) : [] as $target) {
                if (is_array($target) && is_string($target['id'] ?? null)) {
                    $ids[] = mb_substr($target['id'], 0, 255);
                }
            }

            return [
                'event' => $action === 'delete' ? AuditEventType::DomainDeletion : AuditEventType::DomainUpdate,
                'target' => null,
                'details' => ['targetIds' => $ids, 'bulkAction' => is_string($action) ? mb_substr($action, 0, 32) : null],
            ];
        }

        return ['target' => self::named('domain', $request->route('domain') ?? $request->input('name'))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string|null, name: string|null}|null}
     */
    public static function footer(Request $request, array $config): array
    {
        return ['target' => self::named('domainFooter', $request->route('domain'))];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string|null, name: string|null}|null}
     */
    public static function template(Request $request, array $config): array
    {
        $id = $request->route('templateId');

        if (is_string($id) && $id !== '') {
            $name = ctype_digit($id) ? DB::table('templates')->where('type', 'domain')->where('id', (int) $id)->value('template') : null;

            return ['target' => ['type' => 'domainTemplate', 'id' => mb_substr($id, 0, 255), 'name' => is_string($name) ? $name : null]];
        }

        $name = $request->input('name');

        return ['target' => is_string($name) && $name !== '' ? ['type' => 'domainTemplate', 'id' => null, 'name' => mb_substr($name, 0, 255)] : null];
    }

    /**
     * @return array{type: string, id: string, name: string}|null
     */
    public static function named(string $type, mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $name = DomainName::normalize($raw) ?? mb_substr($raw, 0, 255);

        return ['type' => $type, 'id' => mb_substr($name, 0, 255), 'name' => mb_substr(DomainName::toUnicode($name), 0, 255)];
    }
}
