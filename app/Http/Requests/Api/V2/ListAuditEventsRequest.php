<?php

namespace App\Http\Requests\Api\V2;

use App\Http\RequestContext;
use App\Models\AuditEvent;
use App\Rules\IpNetwork;
use App\Rules\MatchesFormat;
use App\Rules\Rfc3339DateTime;
use App\Services\Mailboxes\MailboxAddress;
use App\Support\Http\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Query of `listAuditEvents` (FR-047, FR-059, FR-060): the time range, the repeatable `eventType`
 * and `authMethod` (read from the raw query string, because PHP keeps only the last of repeated
 * names), `outcome`, `actor`, `clientAddress` (an address or a prefix), `targetType`, `targetId`,
 * `impersonatedAccount` (feature 007), `requestId`, and the shared list parameters (`sort`
 * occurredAt, newest first by default; `q` over the actor, the target name and the user agent).
 *
 * Values of the shared enumerations (event type, authentication method, target type) are not
 * checked against a list: one that this server does not record matches nothing (FR-047).
 * Administrators only; the policy runs before validation (R8).
 */
class ListAuditEventsRequest extends ApiFormRequest
{
    public const SORT_COLUMNS = ['occurredAt' => 'occurred_at'];

    /** Query parameters that may be repeated. */
    private const REPEATABLE = ['eventType', 'authMethod'];

    private ?ListQuery $listQuery = null;

    public function authorize(): bool
    {
        return Gate::forUser(RequestContext::requirePrincipal($this))->allows('viewAny', AuditEvent::class);
    }

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
            'from' => ['bail', 'string', new Rfc3339DateTime],
            'to' => ['bail', 'string', new Rfc3339DateTime],
            'eventType' => ['bail', 'array', 'max:20'],
            'eventType.*' => ['bail', 'string', 'max:64'],
            'authMethod' => ['bail', 'array', 'max:20'],
            'authMethod.*' => ['bail', 'string', 'max:64'],
            'outcome' => ['bail', 'string', 'in:success,failure'],
            'actor' => ['bail', 'string', 'max:255'],
            'clientAddress' => ['bail', 'string', new IpNetwork],
            'targetType' => ['bail', 'string', 'max:64'],
            'targetId' => ['bail', 'string', 'max:255'],
            'impersonatedAccount' => ['bail', 'string', 'max:255'],
            'requestId' => ['bail', 'string', new MatchesFormat('/^[0-9a-f]{32}$/', 'requestId')],
        ];
    }

    protected function passedValidation(): void
    {
        $this->listQuery = ListQuery::fromRequest($this, self::SORT_COLUMNS, 'occurredAt', 'desc');
    }

    public function listQuery(): ListQuery
    {
        return $this->listQuery ??= ListQuery::fromRequest($this, self::SORT_COLUMNS, 'occurredAt', 'desc');
    }

    /**
     * The validated filters.
     *
     * @return array{from: CarbonImmutable|null, to: CarbonImmutable|null, eventTypes: list<string>, authMethods: list<string>, outcome: string|null, actor: string|null, clientAddress: string|null, targetType: string|null, targetId: string|null, impersonatedAccount: string|null, requestId: string|null}
     */
    public function filters(): array
    {
        $string = function (string $name): ?string {
            $value = $this->query($name);

            return is_string($value) && $value !== '' ? $value : null;
        };
        $repeated = $this->repeated();
        $time = fn (string $name): ?CarbonImmutable => ($value = $string($name)) === null ? null : Rfc3339DateTime::parse($value);

        return [
            'from' => $time('from'),
            'to' => $time('to'),
            'eventTypes' => $repeated['eventType'] ?? [],
            'authMethods' => $repeated['authMethod'] ?? [],
            'outcome' => $string('outcome'),
            'actor' => $string('actor'),
            'clientAddress' => ($address = $string('clientAddress')) === null ? null : IpNetwork::normalize($address),
            'targetType' => $string('targetType'),
            'targetId' => $string('targetId'),
            'impersonatedAccount' => ($account = $string('impersonatedAccount')) === null ? null : self::account($account),
            'requestId' => $string('requestId'),
        ];
    }

    /**
     * An impersonated account as recorded (feature 007): a mailbox address normalised as feature 003
     * does, otherwise a username trimmed and lower-cased.
     */
    private static function account(string $value): string
    {
        return MailboxAddress::normalize($value)?->address ?? mb_strtolower(trim($value));
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
