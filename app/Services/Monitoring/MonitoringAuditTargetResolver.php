<?php

namespace App\Services\Monitoring;

use App\Audit\AuditEventType;
use App\Services\Audit\RefusalAuditor;
use Illuminate\Http\Request;

/**
 * Target resolvers of feature 011 for the shared refusal auditing (research R24): `container` (the
 * service name as sent), `mailQueue` (no ID; a refused bulk request records the sent hexadecimal
 * queue IDs, at most 1,000, and its event follows the action), `mailQueueMessage` (the queue ID),
 * `fail2banSettings`, `fail2banRules` and `fail2banBanListId` (no ID) and `fail2banActiveBan` (the
 * decoded network). Values are cut to 255 characters. It also maps this feature's failure problem
 * types to their audit failure reasons.
 */
final class MonitoringAuditTargetResolver
{
    public const QUEUE_EVENTS = [
        'deliver' => AuditEventType::MailQueueDelivery,
        'hold' => AuditEventType::MailQueueHold,
        'release' => AuditEventType::MailQueueRelease,
        'delete' => AuditEventType::MailQueueDeletion,
    ];

    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('container', fn (Request $request): array => ['target' => self::named('container', $request->route('containerName'))]);
        $auditor->resolveTargetsWith('mailQueue', self::mailQueue(...));
        $auditor->resolveTargetsWith('mailQueueMessage', fn (Request $request): array => ['target' => self::named('mailQueueMessage', $request->route('queueId'))]);
        $auditor->resolveTargetsWith('fail2banActiveBan', fn (Request $request): array => ['target' => self::named('fail2banActiveBan', self::network($request))]);

        foreach (['fail2banSettings', 'fail2banRules', 'fail2banBanListId'] as $type) {
            $auditor->resolveTargetsWith($type, fn (): array => ['target' => ['type' => $type, 'id' => null, 'name' => null]]);
        }

        $auditor->mapProblemType('/api/v2/problems/container-action-failed', 'serviceFailure');
        $auditor->mapProblemType('/api/v2/problems/queue-action-failed', 'serviceFailure');
        $auditor->mapProblemType('/api/v2/problems/ban-rules-not-restored', 'rulesNotRestored');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: null, name: null}, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function mailQueue(Request $request, array $config): array
    {
        $target = ['type' => 'mailQueue', 'id' => null, 'name' => null];

        if (($config['event'] ?? null) !== 'fromBulkAction') {
            return ['target' => $target];
        }

        $action = $request->input('action');
        $targets = $request->input('targets');
        $ids = [];

        foreach (is_array($targets) ? array_slice($targets, 0, 1000) : [] as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && preg_match('/^[0-9A-Fa-f]{1,32}$/', $item['id']) === 1) {
                $ids[] = $item['id'];
            }
        }

        return [
            'event' => self::QUEUE_EVENTS[is_string($action) ? $action : ''] ?? AuditEventType::MailQueueDeletion,
            'target' => $target,
            'details' => ['itemIds' => $ids, 'bulkAction' => is_string($action) ? mb_substr($action, 0, 32) : null],
        ];
    }

    public static function network(Request $request): ?string
    {
        $network = $request->route('network');

        return is_string($network) ? rawurldecode($network) : null;
    }

    /**
     * @return array{type: string, id: string, name: string}|null
     */
    private static function named(string $type, mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $value = mb_substr($value, 0, 255);

        return ['type' => $type, 'id' => $value, 'name' => $value];
    }
}
