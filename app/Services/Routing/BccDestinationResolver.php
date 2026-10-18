<?php

namespace App\Services\Routing;

use App\Auth\Principal;
use App\Rules\RoutingEmail;
use App\Support\Domain\DomainName;
use Illuminate\Support\Facades\DB;

/**
 * The local destination of a BCC map (feature 009 FR-050, FR-051, research R18), legacy bcc('add')
 * with the spec's deviations. After trimming and lower-casing, within the caller's scope
 * (BccScope):
 *
 *  - a domain or an alias domain, with or without a leading `@`: stored `@<ascii>`;
 *  - a mailbox (not a resource), an alias that is neither a catch-all nor a mailbox's own alias,
 *    or a mailbox's local part at one of its domain's alias domains (legacy refused these although
 *    its own choice list offered them): stored with its ASCII domain.
 *
 * The map's domain is the domain or alias domain of the value. Anything else, and anything outside
 * the scope, resolves to null: one `notFound` error for both, so that a refusal never tells whether
 * a foreign object exists.
 */
class BccDestinationResolver
{
    public const MAX_LENGTH = 255;

    public function __construct(private readonly BccScope $scope) {}

    /**
     * @return array{localDestination: string, domain: string}|null
     */
    public function resolve(Principal $principal, string $raw): ?array
    {
        $value = mb_strtolower(trim($raw));

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        $resolved = str_starts_with($value, '@') || ! str_contains($value, '@') ? $this->domain(ltrim($value, '@')) : $this->address($value);

        if ($resolved === null || strlen($resolved['localDestination']) > self::MAX_LENGTH || ! $this->scope->contains($principal, $resolved['domain'])) {
            return null;
        }

        return $resolved;
    }

    /**
     * @return array{localDestination: string, domain: string}|null
     */
    private function domain(string $value): ?array
    {
        $domain = DomainName::normalizeValid($value);

        if ($domain === null || (! DB::table('domain')->where('domain', $domain)->exists() && ! DB::table('alias_domain')->where('alias_domain', $domain)->exists())) {
            return null;
        }

        return ['localDestination' => '@'.$domain, 'domain' => $domain];
    }

    /**
     * @return array{localDestination: string, domain: string}|null
     */
    private function address(string $value): ?array
    {
        $address = RoutingEmail::normalize($value);

        if ($address === null) {
            return null;
        }

        $address = mb_strtolower($address);
        [$local, $domain] = explode('@', $address, 2);

        if (DB::table('mailbox')->where('username', $address)->where('kind', '')->exists()) {
            return ['localDestination' => $address, 'domain' => $domain];
        }

        $alias = DB::table('alias')->where('address', $address)->where('address', 'not like', '@%')->whereColumn('goto', '<>', 'address')->first(['domain']);

        if ($alias !== null && ! DB::table('mailbox')->where('username', $address)->exists()) {
            return ['localDestination' => $address, 'domain' => $domain];
        }

        $target = DB::table('alias_domain')->where('alias_domain', $domain)->value('target_domain');

        if (is_string($target) && DB::table('mailbox')->where('username', $local.'@'.$target)->where('kind', '')->exists()) {
            return ['localDestination' => $address, 'domain' => $domain];
        }

        return null;
    }
}
