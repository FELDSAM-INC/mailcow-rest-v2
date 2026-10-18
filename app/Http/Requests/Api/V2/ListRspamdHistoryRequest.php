<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\ReadsRepeatedQuery;
use App\Rules\Rfc3339DateTime;
use App\Support\Http\ListQuery;

/**
 * Query of `listRspamdHistory` (feature 011 FR-034): the repeatable `action` (exact Rspamd action
 * names, case-insensitive), `minScore`/`maxScore` (numbers), `from`/`to`, and the shared list
 * parameters (`sort` of `RspamdHistorySort`, default `time` newest first).
 */
class ListRspamdHistoryRequest extends ApiFormRequest
{
    use ReadsRepeatedQuery;

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();
        unset($data['action']);
        $actions = $this->repeatedQuery('action');

        return $actions === [] ? $data : $data + ['action' => $actions];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['bail', 'array', 'max:20'],
            'action.*' => ['bail', 'string', 'min:1', 'max:64'],
            'minScore' => ['bail', 'numeric'],
            'maxScore' => ['bail', 'numeric'],
            'from' => ['bail', new Rfc3339DateTime],
            'to' => ['bail', new Rfc3339DateTime],
        ];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, ['time' => 'time', 'score' => 'score', 'size' => 'size', 'scanTime' => 'scanTime'], 'time', 'desc');
    }

    /**
     * @return list<string>
     */
    public function actions(): array
    {
        return $this->repeatedQuery('action');
    }

    public function score(string $name): ?float
    {
        $value = $this->query($name);

        return is_numeric($value) ? (float) $value : null;
    }
}
