<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Rules\LogCursorFormat;
use App\Rules\Rfc3339DateTime;
use App\Services\Logs\LogQuery;
use App\Services\Logs\LogSeverity;
use App\Services\Logs\LogType;
use App\Support\Http\ListQuery;

/**
 * Query of the twelve log lists (feature 011 FR-024 to FR-026): the shared list parameters (`sort`
 * `time` only, newest first by default), `from`/`to` (RFC 3339), `before` (a cursor of the log),
 * `severity` for logs with a priority, and the log's type-specific filters (repeatable, at most 20
 * values of 1 to 255 characters). The log type is the route default `logType`.
 */
class ListLogEntriesRequest extends ApiFormRequest
{
    use ReadsRepeatedQuery;

    public function logType(): LogType
    {
        return LogType::from((string) $this->route('logType'));
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();

        foreach ($this->repeatable() as $name) {
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
        $type = $this->logType();
        $rules = [
            'from' => ['bail', new Rfc3339DateTime],
            'to' => ['bail', new Rfc3339DateTime],
            'before' => ['bail', 'string', 'max:512', new LogCursorFormat($type)],
        ];

        if ($type->hasSeverity()) {
            $rules['severity'] = ['bail', 'array', 'max:3'];
            $rules['severity.*'] = ['bail', 'string', 'in:'.implode(',', LogSeverity::VALUES)];
        }

        foreach (array_keys($type->filters()) as $name) {
            $rules[$name] = ['bail', 'array', 'max:20'];
            $rules[$name.'.*'] = ['bail', 'string', 'min:1', 'max:255'];
        }

        return $rules;
    }

    public function logQuery(): LogQuery
    {
        $type = $this->logType();
        $list = ListQuery::fromRequest($this, ['time' => 'time'], 'time', 'desc');
        $data = $this->validationData();
        $filters = [];

        foreach ($type->filters() as $name => $field) {
            if (is_array($data[$name] ?? null) && $data[$name] !== []) {
                $filters[$field] = array_values(array_map('strval', $data[$name]));
            }
        }

        return new LogQuery(
            type: $type,
            limit: $list->limit,
            offset: $list->offset,
            order: $list->order,
            search: $list->search,
            from: is_string($data['from'] ?? null) ? Rfc3339DateTime::parse($data['from']) : null,
            to: is_string($data['to'] ?? null) ? Rfc3339DateTime::parse($data['to']) : null,
            before: is_string($data['before'] ?? null) ? $data['before'] : null,
            severities: $type->hasSeverity() && is_array($data['severity'] ?? null) ? array_values(array_unique(array_map('strval', $data['severity']))) : [],
            filters: $filters,
        );
    }

    /**
     * @return list<string>
     */
    private function repeatable(): array
    {
        $type = $this->logType();

        return [...($type->hasSeverity() ? ['severity'] : []), ...array_keys($type->filters())];
    }
}
