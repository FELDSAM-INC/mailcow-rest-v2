<?php

namespace App\Rules;

use App\Support\Domain\DomainName;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * One entry of a domain administrator's domain set (feature 007 FR-018, FR-023, research R6):
 * normalised as feature 002 does (trimmed, lower-cased, UTS #46 to ASCII), then
 *
 *  - `invalidFormat` (`format: domainName`) for a name that is not a valid domain name;
 *  - `notEligible` (`kind: aliasDomain`) for an alias domain name;
 *  - `notFound` for a name that is no domain of this server.
 *
 * One instance validates every entry of a list (`domains.*`); normalised() returns the valid
 * entries, de-duplicated in their first order. An entry may be a string or an assignment object
 * as read (`{domain, ...}`, feature 007 DomainAdminAssignmentInput), whose `domain` is checked.
 */
class AssignableDomain extends CodedRule
{
    /** @var array<string, string> attribute => normalised name */
    private array $normalised = [];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $name = is_array($value) && ! array_is_list($value) ? ($value['domain'] ?? null) : $value;

        if (! is_string($name)) {
            // An assignment object without a `domain` is reported on its member by the request.
            if (! is_array($value) || array_is_list($value)) {
                $this->remember($attribute, $this->violation('invalidType', ['expected' => 'string']), $fail);
            }

            return;
        }

        $violation = $this->checkName($name, $normalised);

        if ($violation !== null) {
            $this->remember($attribute, $violation, $fail);

            return;
        }

        $this->normalised[$attribute] = (string) $normalised;
    }

    protected function check(mixed $value): ?array
    {
        return is_string($value) ? $this->checkName($value, $normalised) : $this->violation('invalidType', ['expected' => 'string']);
    }

    /**
     * The valid entries, normalised and de-duplicated, in the order they were given.
     *
     * @return list<string>
     */
    public function normalised(): array
    {
        return array_values(array_unique($this->normalised));
    }

    /**
     * @return array{code: string, params: array<string, mixed>, message: string}|null
     */
    private function checkName(string $name, ?string &$normalised): ?array
    {
        $normalised = DomainName::normalizeValid($name);

        if ($normalised === null) {
            return $this->violation('invalidFormat', ['format' => 'domainName']);
        }

        if (DB::table('domain')->where('domain', $normalised)->exists()) {
            return null;
        }

        if (DB::table('alias_domain')->where('alias_domain', $normalised)->exists()) {
            return $this->violation('notEligible', ['kind' => 'aliasDomain'], 'An alias domain cannot be assigned; assign its primary domain.');
        }

        return $this->violation('notFound', [], 'No domain of this server has this name.');
    }
}
