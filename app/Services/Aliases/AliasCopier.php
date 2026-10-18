<?php

namespace App\Services\Aliases;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Exceptions\CarriesAuditDetails;
use App\Exceptions\ProblemException;
use App\Exceptions\ProblemRenderer;
use App\Exceptions\ValidationProblemException;
use App\Services\Audit\RefusalAuditor;
use App\Support\Http\ETag;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * The legacy action "Expand alias over alias domains" (feature 004 FR-036, FR-037, research R17):
 * for an alias of a primary domain (otherwise `409 not-primary-domain-alias`), one copy per alias
 * domain of its domain, in name order: `<local part>@<alias domain>` (`@<alias domain>` for a
 * catch-all) with the same destination and the four flags, and no comments. An address in use is
 * skipped, as legacy never tries it; every other copy is a creation by the caller (AliasCreator), in
 * its own transaction with its own audit record carrying `copiedFromAliasId`. A refused copy is
 * skipped with its reason (`aliasLimitReached`, `destinationNotPermitted`) or failed with its
 * problem, and audited as a refused creation.
 */
class AliasCopier
{
    public function __construct(
        private readonly AliasCreator $creator,
        private readonly AliasVersion $version,
        private readonly AliasAddressAvailability $availability,
        private readonly RefusalAuditor $refusals,
    ) {}

    /**
     * @return array{aliasId: int, address: string, copies: list<array<string, mixed>>}
     */
    public function copy(Principal $principal, int $id, ?string $ifMatch, ?string $bulkRequestId = null): array
    {
        $row = DB::table('alias')->where('id', $id)->first();

        if (! $row instanceof stdClass || DB::table('mailbox')->where('username', $row->address)->exists()) {
            throw new NotFoundHttpException('The alias does not exist.');
        }

        if (ETag::preconditionFails($ifMatch, $this->version->forRow($row))) {
            throw new PreconditionFailedHttpException('The alias was changed since it was read.');
        }

        if (DB::table('alias_domain')->where('alias_domain', $row->domain)->exists()) {
            throw new ProblemException(ProblemType::NotPrimaryDomainAlias, "The alias {$row->address} lives in an alias domain; only aliases of a primary domain can be copied.");
        }

        $source = AliasUpdater::values($row);
        $local = substr((string) $row->address, 0, (int) strrpos((string) $row->address, '@'));
        $copies = [];

        foreach (DB::table('alias_domain')->where('target_domain', $row->domain)->orderBy('alias_domain')->pluck('alias_domain') as $aliasDomain) {
            $copies[] = $this->copyTo($principal, $id, $source, $local.'@'.$aliasDomain, (string) $aliasDomain, $bulkRequestId)->toArray();
        }

        return ['aliasId' => $id, 'address' => (string) $row->address, 'copies' => $copies];
    }

    /**
     * @param  array<string, mixed>  $source  the copied alias's values (AliasUpdater::values())
     */
    private function copyTo(Principal $principal, int $sourceId, array $source, string $address, string $aliasDomain, ?string $bulkRequestId): AliasCopyOutcome
    {
        if ($this->availability->holderOf($address) !== null) {
            return AliasCopyOutcome::skipped($aliasDomain, $address, 'addressInUse');
        }

        $input = [
            'address' => $address,
            'destinationType' => $source['destinationType'],
            'active' => $source['active'],
            'senderAllowed' => $source['senderAllowed'],
            'groupwareVisible' => $source['groupwareVisible'],
            'internal' => $source['internal'],
            'publicComment' => '',
            'privateComment' => '',
        ];

        if ($source['destinationType'] === AliasDestination::ADDRESSES) {
            $input['destinations'] = $source['destinations'];
        }

        try {
            return AliasCopyOutcome::created($aliasDomain, $address, $this->creator->create($principal, $input, $bulkRequestId, $sourceId));
        } catch (Throwable $e) {
            $response = ProblemRenderer::render($e, app('request'));
            $problem = (array) json_decode((string) $response->getContent(), true);
            $details = ['copiedFromAliasId' => $sourceId] + ($bulkRequestId === null ? [] : ['bulkRequestId' => $bulkRequestId]);

            if ($response->getStatusCode() >= 500) {
                report($e);
            }

            $this->refusals->record(
                AuditEventType::AliasCreation,
                $principal,
                ['type' => 'alias', 'id' => null, 'name' => $address],
                $response->getStatusCode(),
                (string) ($problem['type'] ?? 'about:blank'),
                is_array($problem['errors'] ?? null) ? $problem['errors'] : null,
                $details + ($e instanceof CarriesAuditDetails ? $e->auditDetails() : []),
            );

            $reason = self::reason($e);

            return $reason === null ? AliasCopyOutcome::failed($aliasDomain, $address, $problem) : AliasCopyOutcome::skipped($aliasDomain, $address, $reason);
        }
    }

    private static function reason(Throwable $e): ?string
    {
        if ($e instanceof ProblemException) {
            return match ($e->type) {
                ProblemType::AddressInUse => 'addressInUse',
                ProblemType::DomainAliasLimitReached => 'aliasLimitReached',
                ProblemType::AttributeNotPermitted => 'destinationNotPermitted',
                default => null,
            };
        }

        if ($e instanceof ValidationProblemException) {
            foreach ($e->errors as $violations) {
                foreach ($violations as $violation) {
                    if (($violation['params']['kind'] ?? null) !== 'externalDestination') {
                        return null;
                    }
                }
            }

            return 'destinationNotPermitted';
        }

        return null;
    }
}
