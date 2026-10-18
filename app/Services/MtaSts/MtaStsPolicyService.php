<?php

namespace App\Services\MtaSts;

use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\Domains\DomainScope;
use App\Support\Clock\Clock;
use App\Support\Http\ETag;
use App\Support\Http\ProblemType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The only writer of mailcow's `mta_sts` table (feature 010 FR-032 to FR-037, research R14):
 * policies of domains in the caller's scope (alias domain names are `404`), stored exactly as
 * legacy stores them (`version` and `mode` lower-case, the MX hosts joined by `,`, `max_age` a
 * decimal string, `active` 0 or 1), each write in one transaction with its audit record, so that a
 * failed record leaves no change. mailcow's `mta-sts.php` and its certificate service read the
 * same row: no further side effect is needed (the certificate follows at the service's next run).
 */
class MtaStsPolicyService
{
    public function __construct(
        private readonly DomainScope $scope,
        private readonly MtaStsAudit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * createMtaStsPolicy: an `INSERT`; an existing policy (also a concurrent creation, through the
     * primary key) is `409 mta-sts-policy-exists`.
     *
     * @param  array{domain: string, version: string, mode: string, mxHosts: list<string>, maxAge: int, active: bool}  $members
     */
    public function create(Principal $principal, array $members): stdClass
    {
        $domain = $this->scope->resolve($principal, $members['domain']) ?? throw new NotFoundHttpException('The domain does not exist.');

        DB::transaction(function () use ($principal, $domain, $members): void {
            if (DB::table('mta_sts')->where('domain', $domain)->lockForUpdate()->exists()) {
                throw new ProblemException(ProblemType::MtaStsPolicyExists, "{$domain} already has an MTA-STS policy.");
            }

            $id = MtaStsPolicyId::next($this->clock, self::timezone());

            try {
                DB::table('mta_sts')->insert([
                    'id' => (int) $id,
                    'domain' => $domain,
                    'version' => strtolower($members['version']),
                    'mode' => strtolower($members['mode']),
                    'mx' => implode(',', $members['mxHosts']),
                    'max_age' => (string) $members['maxAge'],
                    'active' => $members['active'] ? 1 : 0,
                ]);
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw new ProblemException(ProblemType::MtaStsPolicyExists, "{$domain} already has an MTA-STS policy.");
                }

                throw $e;
            }

            $this->audit->creation($principal, $domain, self::values($this->row($domain)));
        });

        return $this->row($domain);
    }

    /**
     * updateMtaStsPolicy: the row is read `FOR UPDATE` and compared with `If-Match`; the members
     * sent replace the stored ones; a change of version, mode, MX hosts (order included) or maximum
     * age renews the identifier. A patch that changes nothing writes and audits nothing.
     *
     * @param  array{version?: string, mode?: string, mxHosts?: list<string>, maxAge?: int, active?: bool}  $members
     */
    public function update(Principal $principal, string $rawDomain, array $members, ?string $ifMatch): stdClass
    {
        $domain = $this->scope->resolve($principal, $rawDomain) ?? throw new NotFoundHttpException('The domain has no MTA-STS policy.');

        DB::transaction(function () use ($principal, $domain, $members, $ifMatch): void {
            $row = DB::table('mta_sts')->where('domain', $domain)->lockForUpdate()->first();

            if (! $row instanceof stdClass) {
                throw new NotFoundHttpException('The domain has no MTA-STS policy.');
            }

            if (ETag::preconditionFails($ifMatch, self::versionTag($row))) {
                throw new PreconditionFailedHttpException('The MTA-STS policy was changed since it was read.');
            }

            $old = self::values($row);
            $new = [
                'version' => isset($members['version']) ? strtolower($members['version']) : (string) $row->version,
                'mode' => isset($members['mode']) ? strtolower($members['mode']) : (string) $row->mode,
                'mx' => isset($members['mxHosts']) ? implode(',', $members['mxHosts']) : (string) $row->mx,
                'max_age' => isset($members['maxAge']) ? (string) $members['maxAge'] : (string) $row->max_age,
                'active' => isset($members['active']) ? ($members['active'] ? 1 : 0) : ((int) $row->active !== 0 ? 1 : 0),
            ];
            $contentChanged = $new['version'] !== (string) $row->version || $new['mode'] !== (string) $row->mode
                || $new['mx'] !== (string) $row->mx || $new['max_age'] !== (string) $row->max_age;

            if (! $contentChanged && $new['active'] === ((int) $row->active !== 0 ? 1 : 0)) {
                return;
            }

            $new['id'] = $contentChanged ? (int) MtaStsPolicyId::next($this->clock, self::timezone(), (string) $row->id) : (int) $row->id;
            DB::table('mta_sts')->where('domain', $domain)->update($new);
            $values = self::values($this->row($domain));
            $changes = [];

            foreach (['version', 'mode', 'mxHosts', 'maxAge', 'active'] as $member) {
                if ($old[$member] !== $values[$member]) {
                    $changes[$member] = ['old' => $old[$member], 'new' => $values[$member]];
                }
            }

            $this->audit->update($principal, $domain, $changes, $old['policyId'], $values['policyId']);
        });

        return $this->row($domain);
    }

    /**
     * The policy of a domain in the caller's scope, or `404`.
     */
    public function find(Principal $principal, string $rawDomain): stdClass
    {
        $domain = $this->scope->resolve($principal, $rawDomain) ?? throw new NotFoundHttpException('The domain has no MTA-STS policy.');
        $row = DB::table('mta_sts')->where('domain', $domain)->first();

        return $row instanceof stdClass ? $row : throw new NotFoundHttpException('The domain has no MTA-STS policy.');
    }

    /**
     * The version tag of a stored row (data-model): 001's ETag over `[domain, version, mode, mx,
     * max_age, active, id]` as stored, so that legacy edits change it.
     */
    public static function versionTag(stdClass $row): string
    {
        return ETag::of([(string) $row->domain, (string) $row->version, (string) $row->mode, (string) $row->mx, (string) $row->max_age, (int) $row->active, (string) $row->id]);
    }

    /**
     * The stored values in contract form, for the audit records.
     *
     * @return array{version: string, mode: string, mxHosts: list<string>, maxAge: int, active: bool, policyId: string}
     */
    public static function values(stdClass $row): array
    {
        return [
            'version' => MtaStsPolicyText::version((string) $row->version),
            'mode' => (string) $row->mode,
            'mxHosts' => array_values(array_filter(explode(',', (string) $row->mx), fn (string $h): bool => $h !== '')),
            'maxAge' => (int) $row->max_age,
            'active' => (int) $row->active !== 0,
            'policyId' => (string) $row->id,
        ];
    }

    private function row(string $domain): stdClass
    {
        $row = DB::table('mta_sts')->where('domain', $domain)->first();

        return $row instanceof stdClass ? $row : throw new NotFoundHttpException('The domain has no MTA-STS policy.');
    }

    private static function timezone(): string
    {
        return (string) (config('restv2.timezone') ?: 'UTC');
    }
}
