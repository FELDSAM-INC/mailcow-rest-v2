<?php

namespace App\Services\MtaSts;

use App\Services\Audit\RefusalAuditor;
use App\Support\Domain\DomainName;
use Illuminate\Http\Request;

/**
 * Target resolver of feature 010 for the shared refusal auditing (research R22): `mtaStsPolicy`,
 * the path domain of a change or the body `domain` of a creation, normalised when it can be,
 * otherwise kept as sent, cut to 255 characters.
 */
final class MtaStsAuditTargets
{
    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('mtaStsPolicy', self::policy(...));
        $auditor->mapProblemType('/api/v2/problems/mta-sts-policy-exists', 'conflict');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target: array{type: string, id: string, name: string}|null}
     */
    public static function policy(Request $request, array $config): array
    {
        $path = $request->route('policyDomain');

        return ['target' => self::target(is_string($path) && $path !== '' ? $path : $request->input('domain'))];
    }

    /**
     * @return array{type: string, id: string, name: string}|null
     */
    public static function target(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $name = DomainName::normalize($raw) ?? $raw;

        return ['type' => 'mtaStsPolicy', 'id' => mb_substr($name, 0, 255), 'name' => mb_substr(DomainName::toUnicode($name), 0, 255)];
    }
}
