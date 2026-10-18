<?php

namespace App\Services\SyncJobs;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Requests\Api\V2\ApiFormRequest;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The target mailbox of a new sync job (feature 008 FR-003, FR-017, research R8). A mailbox user's
 * job always targets their own mailbox. An administrator or domain administrator names it: an
 * address that does not exist or lies outside the caller's scope answers `404`, exactly like an
 * unknown job; a resource, an alias or an address of an alias domain in scope is refused with
 * `notEligible`, because it cannot receive synced mail; a mailbox resolves to its stored username,
 * which the job stores (legacy stores the address as typed). An inactive mailbox is accepted.
 */
class SyncJobTargetResolver
{
    /**
     * @return array{mailbox: string|null, violation: array{code: string, params: array<string, mixed>, message: string}|null}
     *
     * @throws NotFoundHttpException for an unknown or out-of-scope target
     */
    public function resolve(mixed $mailbox, Principal $principal): array
    {
        if ($mailbox !== null && ! is_string($mailbox)) {
            return self::violation('invalidType', ['expected' => 'string']);
        }

        if ($principal->role === Role::MailboxUser) {
            if ($mailbox === null || mb_strtolower(trim($mailbox)) === mb_strtolower($principal->username)) {
                return ['mailbox' => $principal->username, 'violation' => null];
            }

            throw self::notFound();
        }

        if ($mailbox === null) {
            return self::violation('required', []);
        }

        $address = trim($mailbox);

        if (mb_strlen($address) > 255) {
            return self::violation('tooLong', ['max' => 255]);
        }

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return self::violation('invalidFormat', ['format' => 'email']);
        }

        $row = DB::table('mailbox')->where('username', $address)->first(['username', 'domain', 'kind']);

        if ($row !== null) {
            if (! $this->domainInScope($principal, (string) $row->domain)) {
                throw self::notFound();
            }

            return (string) $row->kind === ''
                ? ['mailbox' => (string) $row->username, 'violation' => null]
                : self::violation('notEligible', ['kind' => 'resource'], 'A resource cannot receive synced mail.');
        }

        $aliasDomain = DB::table('alias')->where('address', $address)->value('domain');

        if (is_string($aliasDomain) && $this->domainInScope($principal, $aliasDomain)) {
            return self::violation('notEligible', ['kind' => 'alias'], 'An alias cannot receive synced mail; name a mailbox.');
        }

        $target = DB::table('alias_domain')->where('alias_domain', substr((string) strrchr($address, '@'), 1))->value('target_domain');

        if (is_string($target) && $this->domainInScope($principal, $target)) {
            return self::violation('notEligible', ['kind' => 'aliasDomainAddress'], 'An address of an alias domain cannot receive synced mail; name the mailbox.');
        }

        throw self::notFound();
    }

    private function domainInScope(Principal $principal, string $domain): bool
    {
        return match ($principal->role) {
            Role::Admin => true,
            Role::DomainAdmin => DB::table('domain_admins')->where('username', $principal->username)->where('domain', $domain)->where('active', 1)->exists(),
            Role::MailboxUser => false,
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{mailbox: null, violation: array{code: string, params: array<string, mixed>, message: string}}
     */
    private static function violation(string $code, array $params, ?string $message = null): array
    {
        return ['mailbox' => null, 'violation' => ApiFormRequest::make($code, $params, $message)];
    }

    private static function notFound(): NotFoundHttpException
    {
        return new NotFoundHttpException('The mailbox does not exist.');
    }
}
