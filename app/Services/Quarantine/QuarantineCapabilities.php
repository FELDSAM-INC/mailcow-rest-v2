<?php

namespace App\Services\Quarantine;

use App\Http\Requests\Api\V2\QuarantineBulkActionRequest;
use App\Support\Http\ListQuery;

/**
 * The quarantine capabilities (feature 006 FR-072, contract `QuarantineCapabilities`): the limits
 * and server settings that the quarantine, spam policy and rate limit forms need, from the
 * server's configuration. No secret and no connection detail.
 */
class QuarantineCapabilities
{
    /** The largest message the mail pipeline hands to the quarantine (legacy 10 MiB). */
    public const PIPELINE_MAX_MESSAGE_SIZE = 10485760;

    public const MIB = 1048576;

    public const MAX_INT = 2147483647;

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        return [
            'bulkLimit' => QuarantineBulkActionRequest::LIMIT,
            'defaultPageSize' => ListQuery::DEFAULT_LIMIT,
            'maxPageSize' => ListQuery::MAX_LIMIT,
            'contentSizeLimit' => QuarantineContent::limit(),
            'pipelineMaxMessageSize' => self::PIPELINE_MAX_MESSAGE_SIZE,
            'spamScore' => ['min' => 0, 'max' => 5000, 'step' => 0.1],
            'senderPattern' => ['maxLength' => 100, 'allowedCharacters' => 'a-z0-9@_.*-', 'wildcard' => '*'],
            'rateLimit' => ['min' => 1, 'max' => self::MAX_INT, 'frames' => ['second', 'minute', 'hour', 'day']],
            'settings' => [
                'maxRetentionPerMailbox' => self::MAX_INT,
                'maxMessageSizeStep' => self::MIB,
                'maxMessageSize' => self::MAX_INT * self::MIB,
                'maxAgeDays' => ['min' => 1, 'max' => self::MAX_INT, 'default' => 365],
                'notificationSubjectMaxLength' => 255,
                'notificationTemplateMaxBytes' => self::MIB,
                'templateRenderTimeoutSeconds' => max(1, (int) config('restv2.quarantine.template_timeout', 5)),
                'releaseFormats' => ['original', 'attachment'],
            ],
            // mailcow.conf SKIP_CLAMD, matched as legacy matches it (^([yY][eE][sS]|[yY])+$).
            'virusScanning' => preg_match('/^([yY][eE][sS]|[yY])+$/', (string) config('restv2.skip_clamd', 'n')) !== 1,
        ];
    }
}
