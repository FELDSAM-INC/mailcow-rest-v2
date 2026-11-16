<?php

namespace App\Services\IdentityProvider;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Casts\MailcowDateTime;
use App\Exceptions\ValidationProblemException;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Models\Mailcow\SchemaVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Logs\SecretMasker;
use App\Support\Database\NamedLock;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/** The upstream cron executes pending runs; the API never executes arbitrary cron commands. */
final class IdentitySyncService
{
    public function __construct(
        private readonly IdentityProviderRepository $repository,
        private readonly NamedLock $lock,
        private readonly AuditLogger $audit,
    ) {}

    public function assertAvailable(): void
    {
        if (! SchemaVersion::hasNovember2026Features() || ! $this->repository->current()?->type->offersSynchronisation()) {
            throw new ConflictHttpException('Identity synchronisation requires mailcow 2026-11 with LDAP or Keycloak configured.');
        }
    }

    /** @return array<string, mixed> */
    public function read(): array
    {
        $supported = SchemaVersion::hasNovember2026Features();
        $config = $this->repository->current();
        $rows = $this->repository->rows();
        $settings = IdentitySyncSettings::read($rows);
        $provider = $config?->type->legacy();
        $canSync = $supported && ($config?->type->offersSynchronisation() ?? false);
        $counts = ['accounts' => 0, 'linked' => 0, 'disabled' => 0, 'missing' => 0];
        if ($supported && $provider !== null) {
            $row = DB::table('mailbox as m')->leftJoin('identity_link as l', function ($join) use ($provider): void {
                $join->on('l.username', '=', 'm.username')->where('l.provider', $provider);
            })->where('m.authsource', $provider)->where('m.kind', '')
                ->selectRaw('COUNT(*) AS accounts, COUNT(l.external_id) AS linked, COALESCE(SUM(l.idp_disabled),0) AS disabled, COUNT(l.missing_since) AS missing')->first();
            $counts = array_map(intval(...), (array) $row);
        }

        return [
            'supported' => $supported, 'provider' => $config?->type->value, 'canSync' => $canSync,
            'enabled' => $canSync && ((bool) ($rows['periodic_sync'] ?? false) || (bool) ($rows['import_users'] ?? false)
                || $settings['offboarding'] || $settings['groupSync']),
            'settings' => $settings, 'counts' => $counts, 'etag' => $this->repository->etag($rows),
            'runs' => $supported ? DB::table('identity_sync_run')->orderByDesc('id')->limit(10)->get()->map(self::presentRun(...))->all() : [],
        ];
    }

    /** @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function update(array $patch, ?string $ifMatch, Principal $actor): array
    {
        $this->lock->run([IdentityProviderConfigService::LOCK], fn () => DB::transaction(function () use ($patch, $ifMatch, $actor): void {
            $this->assertAvailable();
            $rows = $this->repository->rows();
            if (ETag::preconditionFails($ifMatch, $this->repository->etag($rows))) {
                throw new PreconditionFailedHttpException('The identity provider settings changed since they were read.');
            }
            $provider = $rows['authsource'];
            $old = IdentitySyncSettings::read($rows);
            $changes = [];
            foreach ($patch as $member => $value) {
                if (($provider !== 'ldap' && in_array($member, IdentitySyncSettings::LDAP_FIELDS, true))
                    || ($provider !== 'keycloak' && $member === 'groupPaths')) {
                    throw new ValidationProblemException([$member => [ApiFormRequest::make('conflictsWith', ['field' => 'type', 'value' => $provider])]]);
                }
                if (in_array($member, ['idAttribute', 'groupNameAttribute', 'groupMemberAttribute', 'groupDefaultDomain'], true)) {
                    $value = strtolower(trim((string) $value));
                }
                if ($old[$member] === $value) {
                    continue;
                }
                DB::statement('INSERT INTO identity_provider (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [IdentitySyncSettings::KEYS[$member], IdentitySyncSettings::encode($member, $value)]);
                $changes[$member] = ['old' => $old[$member], 'new' => $value];
                if ($member === 'idAttribute') {
                    // Match upstream: preserve offboarding markers, but IDs in another namespace cannot match.
                    DB::table('identity_link')->where('provider', 'ldap')->update(['external_id' => null]);
                }
            }
            $this->audit->record(AuditEventType::IdentitySyncSettingsUpdate, true, $actor,
                ['type' => 'identityProvider', 'id' => $provider], null, null, ['changes' => $changes]);
        }));

        return $this->read();
    }

    /** @return array<string, mixed> */
    public function requestRun(string $mode, Principal $actor): array
    {
        return $this->lock->run([IdentityProviderConfigService::LOCK], fn (): array => DB::transaction(function () use ($mode, $actor): array {
            $this->assertAvailable();
            $provider = $this->repository->current()?->type->legacy();
            $existing = DB::table('identity_sync_run')->where('provider', $provider)->where('status', 'pending')->where('mode', $mode)->lockForUpdate()->first();
            $id = $existing->id ?? DB::table('identity_sync_run')->insertGetId([
                'provider' => $provider, 'mode' => $mode, 'status' => 'pending', 'requested_by' => $actor->username,
            ]);
            $this->audit->record(AuditEventType::IdentitySyncRequested, true, $actor,
                ['type' => 'identityProvider', 'id' => $provider], null, null, ['mode' => $mode, 'runId' => (int) $id]);

            return self::presentRun(DB::table('identity_sync_run')->where('id', $id)->firstOrFail());
        }));
    }

    /** @return array<string, mixed>|null */
    public function link(string $mailbox): ?array
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            return null;
        }
        $row = DB::table('identity_link')->where('username', $mailbox)->first();

        return $row === null ? null : [
            'mailbox' => (string) $row->username, 'provider' => (string) $row->provider,
            'externalId' => $row->external_id, 'disabled' => (bool) $row->idp_disabled,
            'missingSince' => MailcowDateTime::toUtcString($row->missing_since), 'etag' => ETag::of((array) $row),
        ];
    }

    public function unlink(string $mailbox, ?string $ifMatch, Principal $actor): void
    {
        if (! SchemaVersion::hasNovember2026Features()) {
            throw new NotFoundHttpException;
        }
        $this->lock->run([IdentityProviderConfigService::LOCK], fn () => DB::transaction(function () use ($mailbox, $ifMatch, $actor): void {
            // Same order as login: mailbox first, then its identity link.
            DB::table('mailbox')->where('username', $mailbox)->lockForUpdate()->firstOrFail();
            $row = DB::table('identity_link')->where('username', $mailbox)->lockForUpdate()->first();
            if ($row === null) {
                throw new NotFoundHttpException;
            }
            if (ETag::preconditionFails($ifMatch, ETag::of((array) $row))) {
                throw new PreconditionFailedHttpException('The identity link changed since it was read.');
            }
            DB::table('identity_link')->where('username', $mailbox)->delete();
            $this->audit->record(AuditEventType::IdentityLinkDeletion, true, $actor,
                ['type' => 'identityProvider', 'id' => (string) $row->provider], null, null, ['mailbox' => $mailbox]);
        }));
    }

    /** @return array<string, mixed> */
    private static function presentRun(object $row): array
    {
        $summary = json_decode((string) ($row->summary ?? ''), true, 32);
        // Preserve upstream conflict details while excluding unexpected fields and named secrets.
        $summary = is_array($summary) ? app(SecretMasker::class)->mask(array_intersect_key($summary,
            array_flip(['counts', 'details', 'blocked', 'error', 'errors', 'warnings']))) : null;

        return [
            'id' => (int) $row->id, 'provider' => (string) $row->provider, 'mode' => (string) $row->mode,
            'status' => (string) $row->status, 'requestedBy' => (string) $row->requested_by,
            'createdAt' => MailcowDateTime::toUtcString($row->created), 'startedAt' => MailcowDateTime::toUtcString($row->started),
            'finishedAt' => MailcowDateTime::toUtcString($row->finished), 'summary' => $summary === [] ? (object) [] : $summary,
        ];
    }
}
