<?php

namespace App\Http\Requests\Api\V2;

use App\Rules\MailQueueId;
use App\Rules\UnknownField;

/**
 * Body of `bulkMailQueueMessages` (feature 011 FR-040, `MailQueueBulkRequest`): `action` one of
 * deliver, hold, release, delete (`notInList`); 1 to 1,000 `targets` (`required`, `tooMany`);
 * each `targets.<n>.id` hexadecimal (`invalidFormat`, `format: queueId`); queued messages carry no
 * version tag, so `targets.<n>.version` is refused (`unknownField`). Any invalid entry refuses the
 * whole request.
 */
class MailQueueBulkActionRequest extends ApiFormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'required', 'string', 'in:deliver,hold,release,delete'],
            'targets' => ['bail', 'required', 'array', 'list', 'min:1', 'max:'.(int) config('restv2.monitoring.queue_bulk_max', 1000)],
            'targets.*' => ['bail', 'array'],
            'targets.*.id' => ['bail', 'required', 'string', new MailQueueId],
            'targets.*.version' => [new UnknownField],
        ];
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_values(array_map(fn (array $target): string => (string) $target['id'], (array) $this->validated('targets')));
    }
}
