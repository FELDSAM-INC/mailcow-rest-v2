<?php

namespace App\Services\Dkim;

use App\Audit\AuditEventType;
use App\Clients\SharedStore\DkimKeyStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Audit\RefusalAuditor;
use App\Support\Domain\DomainName;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Target resolver of feature 010 for the shared refusal auditing (research R22): `dkimKey`. A
 * refusal names the key: the path name (an unused key that legacy stored under another spelling by
 * that exact spelling, any other name normalised), else the body `target` of a copy or the body
 * `name` of a generation. Names that cannot be normalised are kept as sent; every name is cut to
 * 255 characters. A refused bulk request is recorded once with a null target and the requested IDs.
 * The private key is never read.
 */
final class DkimAuditTargets
{
    public static function register(RefusalAuditor $auditor): void
    {
        $auditor->resolveTargetsWith('dkimKey', self::dkimKey(...));
        $auditor->mapProblemType('/api/v2/problems/dkim-key-exists', 'conflict');
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function dkimKey(Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            return RefusalAuditor::bulkRefusal($request, AuditEventType::DkimKeyDeletion);
        }

        $path = $request->route('dkimName');

        if (is_string($path) && $path !== '') {
            return ['target' => ($config['event'] ?? null) === 'dkimKeyCopy'
                ? self::named($request->input('target'))
                : self::pathName($path)];
        }

        return ['target' => self::named($request->input('name'))];
    }

    /**
     * The target of a name given in a request body or a bulk item.
     *
     * @return array{type: string, id: string, name: string}|null
     */
    public static function named(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $name = DomainName::normalize($raw) ?? $raw;

        return self::target($name);
    }

    /**
     * The audit target of a stored key name.
     *
     * @return array{type: string, id: string, name: string}
     */
    public static function target(string $name): array
    {
        $display = DomainName::normalize($name) === $name ? DomainName::toUnicode($name) : $name;

        return ['type' => 'dkimKey', 'id' => mb_substr($name, 0, 255), 'name' => mb_substr($display, 0, 255)];
    }

    /**
     * @return array{type: string, id: string, name: string}|null
     */
    private static function pathName(string $raw): ?array
    {
        try {
            $legacy = app(DkimKeyStore::class)->hasKey($raw)
                && ! DB::table('domain')->where('domain', $raw)->pluck('domain')->contains($raw)
                && ! DB::table('alias_domain')->where('alias_domain', $raw)->pluck('alias_domain')->contains($raw);
        } catch (SharedStoreUnavailable) {
            $legacy = false;
        }

        return $legacy ? self::target($raw) : self::named($raw);
    }
}
