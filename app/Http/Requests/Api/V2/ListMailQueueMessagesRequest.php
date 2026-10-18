<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Rules\Rfc3339DateTime;
use App\Services\MailQueue\MailQueueListing;
use App\Services\MailQueue\MailQueueQuery;
use App\Support\Http\ListQuery;

/**
 * Query of `listMailQueueMessages` (feature 011 FR-038): the repeatable `queue` (the five Postfix
 * queues), `sender` and `recipient` filters, `from`/`to` on the arrival time, and the shared list
 * parameters (`sort` of `MailQueueMessageSort`, default `arrivalTime` ascending).
 */
class ListMailQueueMessagesRequest extends ApiFormRequest
{
    use ReadsRepeatedQuery;

    private const REPEATABLE = ['queue', 'sender', 'recipient'];

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();

        foreach (self::REPEATABLE as $name) {
            unset($data[$name]);
            $values = $this->repeatedQuery($name);

            if ($values !== []) {
                $data[$name] = $values;
            }
        }

        return $data;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'queue' => ['bail', 'array', 'max:5'],
            'queue.*' => ['bail', 'string', 'in:'.implode(',', MailQueueListing::QUEUES)],
            'sender' => ['bail', 'array', 'max:20'],
            'sender.*' => ['bail', 'string', 'min:1', 'max:255'],
            'recipient' => ['bail', 'array', 'max:20'],
            'recipient.*' => ['bail', 'string', 'min:1', 'max:255'],
            'from' => ['bail', new Rfc3339DateTime],
            'to' => ['bail', new Rfc3339DateTime],
        ];
    }

    public function mailQueueQuery(): MailQueueQuery
    {
        $list = ListQuery::fromRequest($this, ['arrivalTime' => 'arrivalTime', 'size' => 'size', 'sender' => 'sender', 'queue' => 'queue', 'id' => 'id'], 'arrivalTime', 'asc');
        $data = $this->validationData();
        $values = fn (string $name): array => is_array($data[$name] ?? null) ? array_values(array_map('strval', $data[$name])) : [];

        return new MailQueueQuery(
            limit: $list->limit,
            offset: $list->offset,
            sort: $list->sort,
            order: $list->order,
            search: $list->search,
            from: is_string($data['from'] ?? null) ? Rfc3339DateTime::parse($data['from']) : null,
            to: is_string($data['to'] ?? null) ? Rfc3339DateTime::parse($data['to']) : null,
            queues: array_values(array_unique($values('queue'))),
            senders: $values('sender'),
            recipients: $values('recipient'),
        );
    }
}
