<?php

namespace App\Services\AliasDomains;

use App\Auth\Principal;
use App\Services\Domains\DomainScope;
use App\Support\Domain\DomainName;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;

/**
 * The rules on an alias domain's target (feature 004 FR-041, FR-044, research R12): an existing
 * domain in the caller's scope (`notFound`, the same for a domain outside the scope), not an alias
 * domain (`notEligible`, `kind: aliasDomain`) and not a relay domain (`notEligible`,
 * `kind: relayDomain`).
 */
class AliasDomainRules
{
    public function __construct(private readonly DomainScope $scope) {}

    /** The normalised target, or null after recording its violation. */
    public function target(Principal $principal, mixed $raw, FieldViolations $violations): ?string
    {
        $name = is_string($raw) ? DomainName::normalizeValid($raw) : null;

        if ($name === null) {
            $violations->add('targetDomain', 'notFound', ['resource' => 'domain'], 'The domain does not exist.');

            return null;
        }

        $aliasDomainTarget = DB::table('alias_domain')->where('alias_domain', $name)->value('target_domain');

        if ($aliasDomainTarget !== null && $this->scope->resolve($principal, (string) $aliasDomainTarget) !== null) {
            $violations->add('targetDomain', 'notEligible', ['kind' => 'aliasDomain'], 'An alias domain cannot be the target of an alias domain.');

            return null;
        }

        if ($this->scope->resolve($principal, $name) === null) {
            $violations->add('targetDomain', 'notFound', ['resource' => 'domain'], 'The domain does not exist.');

            return null;
        }

        if ((int) DB::table('domain')->where('domain', $name)->value('backupmx') === 1) {
            $violations->add('targetDomain', 'notEligible', ['kind' => 'relayDomain'], 'A relay (backup MX) domain cannot be the target of an alias domain.');

            return null;
        }

        return $name;
    }
}
