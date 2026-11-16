<?php

namespace App\Services\IdentityProvider;

use App\Models\Mailcow\SchemaVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** 2026-11 links an address to a stable IdP identity, preventing reassigned-address takeover. */
final class IdentityLinkGuard
{
    public function mayCreate(string $provider, string $username, ?string $externalId): bool
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            return true;
        }
        if ($externalId === null || $externalId === '' || strlen($externalId) > 255) {
            return false;
        }
        $owner = DB::table('identity_link')->where('provider', $provider)->where('external_id', $externalId)->value('username');

        return $owner === null || strcasecmp((string) $owner, $username) === 0;
    }

    public function verify(string $provider, string $username, ?string $externalId): bool
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            return true;
        }
        if (! $this->mayCreate($provider, $username, $externalId)) {
            return false;
        }
        try {
            return DB::transaction(function () use ($provider, $username, $externalId): bool {
                $mailbox = DB::table('mailbox')->where('username', $username)->lockForUpdate()->first(['authsource', 'active', 'attributes']);
                if ($mailbox === null || $mailbox->authsource !== $provider || (int) $mailbox->active !== 1
                    || (int) (json_decode((string) $mailbox->attributes, true)['shared'] ?? 0) === 1) {
                    return false;
                }
                $link = DB::table('identity_link')->where('username', $username)->lockForUpdate()->first();
                if ($link !== null && $link->provider === $provider && $link->external_id !== null) {
                    return (int) $link->idp_disabled === 0 && hash_equals((string) $link->external_id, (string) $externalId);
                }
                DB::table('identity_link')->updateOrInsert(['username' => $username], [
                    'provider' => $provider, 'external_id' => $externalId, 'idp_disabled' => 0, 'missing_since' => null,
                ]);

                return true;
            });
        } catch (QueryException $e) {
            // Another mailbox may have claimed the provider identity after mayCreate().
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }
}
