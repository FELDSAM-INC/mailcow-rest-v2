<?php

namespace App\Http\Requests\Api\V2;

use App\Rules\QueryBoolean;
use App\Rules\Rfc3339DateTime;
use App\Services\Quarantine\QuarantineQuery;
use App\Support\Domain\DomainName;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;

/**
 * Query of `listQuarantineItems` (feature 006 research R16, FR-008 to FR-011): the shared list
 * parameters (`limit` 25 by default, at most 1000; `offset`; `sort`, newest first by default;
 * `order`; `q`) and the filters: `recipient` (case-insensitive), `domain` (ASCII or Unicode form,
 * normalised as feature 002 does), `action` (repeatable, read from the raw query string as 001's
 * audit filters are), `virus`, `notified`, `from`/`to` (RFC 3339) and `minScore`/`maxScore`.
 */
class ListQuarantineItemsRequest extends ApiFormRequest
{
    /** Query parameters that may be repeated. */
    private const REPEATABLE = ['action'];

    /**
     * The query, with every value of the repeatable parameters.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        $data = $this->query();

        foreach (self::REPEATABLE as $name) {
            unset($data[$name]);
        }

        return $data + $this->repeated();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'recipient' => ['bail', 'string', 'max:255'],
            'domain' => ['bail', 'string', 'max:255'],
            'action' => ['bail', 'array', 'max:4'],
            'virus' => ['bail', new QueryBoolean],
            'notified' => ['bail', new QueryBoolean],
            'from' => ['bail', 'string', new Rfc3339DateTime],
            'to' => ['bail', 'string', new Rfc3339DateTime],
            'minScore' => ['bail', 'numeric'],
            'maxScore' => ['bail', 'numeric'],
        ];
    }

    /**
     * An `action` value outside the enumeration is reported on `action` itself (`notInList`).
     */
    protected function additionalViolations(Validator $validator): array
    {
        $allowed = ['rejected', 'junk', 'subjectRewritten', 'other'];

        foreach ($this->repeated()['action'] ?? [] as $value) {
            if (! in_array($value, $allowed, true)) {
                return ['action' => [self::make('notInList', ['allowed' => $allowed])]];
            }
        }

        return [];
    }

    public function listQuery(): ListQuery
    {
        return ListQuery::fromRequest($this, QuarantineQuery::SORTS, 'arrivedAt', 'desc');
    }

    /**
     * The validated filters.
     *
     * @return array{recipient: string|null, domain: string|null, actions: list<string>, virus: bool|null, notified: bool|null, from: CarbonImmutable|null, to: CarbonImmutable|null, minScore: float|null, maxScore: float|null}
     */
    public function filters(): array
    {
        $string = function (string $name): ?string {
            $value = $this->query($name);

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };
        $bool = fn (string $name): ?bool => ($value = $string($name)) === null ? null : $value === 'true';
        $time = fn (string $name): ?CarbonImmutable => ($value = $string($name)) === null ? null : Rfc3339DateTime::parse($value);
        $number = fn (string $name): ?float => ($value = $string($name)) === null ? null : (float) $value;
        $domain = $string('domain');

        return [
            'recipient' => ($recipient = $string('recipient')) === null ? null : mb_strtolower($recipient),
            'domain' => $domain === null ? null : (DomainName::normalize($domain) ?? mb_strtolower($domain)),
            'actions' => array_values(array_unique($this->repeated()['action'] ?? [])),
            'virus' => $bool('virus'),
            'notified' => $bool('notified'),
            'from' => $time('from'),
            'to' => $time('to'),
            'minScore' => $number('minScore'),
            'maxScore' => $number('maxScore'),
        ];
    }

    /**
     * Every value of the repeatable parameters, from the raw query string (`a=1&a=2`).
     *
     * @return array<string, list<string>>
     */
    private function repeated(): array
    {
        $values = [];

        foreach (explode('&', (string) $this->server('QUERY_STRING', '')) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);

            if (in_array($name, self::REPEATABLE, true)) {
                $values[$name][] = urldecode($value);
            }
        }

        return $values;
    }
}
