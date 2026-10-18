<?php

namespace App\Services\IdentityProvider;

use App\Audit\AuditEventType;
use App\Auth\IdentityProviderType;
use App\Auth\Principal;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Support\Database\NamedLock;
use App\Support\Http\ETag;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The upstream writes of the identity provider configuration (FR-009 to FR-017, FR-050 to FR-052,
 * research R9, data-model): whole save, change and deletion of the `identity_provider` rows, each
 * under `GET_LOCK('restv2_identity_provider', 5)` (two first saves cannot both create) and in one
 * transaction with its audit record.
 *
 *  - A save writes exactly the keys of the saved type with legacy's encodings, removes every other
 *    row, and clears the cached Keycloak service token, as legacy identity_provider('edit') does
 *    (except that legacy keeps the rows of a previous type, FR-015). No mailbox changes.
 *  - A save of another type, and a deletion, are refused while mailboxes authenticate against an
 *    external source the request would remove (`identity-provider-in-use` with the counts).
 *  - Refusals are thrown and recorded by the shared RefusalAuditor, never here.
 */
class IdentityProviderConfigService
{
    public const LOCK = 'restv2_identity_provider';

    public function __construct(
        private readonly IdentityProviderRepository $repository,
        private readonly IdentityProviderSettingsCodec $codec,
        private readonly IdentityProviderUsage $usage,
        private readonly SecretRetentionPolicy $secrets,
        private readonly AuditLogger $audit,
        private readonly NamedLock $lock,
    ) {}

    /**
     * Creates or replaces the configuration with a whole one of the given type.
     *
     * @param  array<string, mixed>  $input  the sent members, `type` excluded
     * @return array{config: IdentityProviderConfig, created: bool}
     */
    public function save(IdentityProviderType $type, array $input, ?string $ifMatch, ?string $ifNoneMatch, Principal $actor): array
    {
        return $this->lock->run([self::LOCK], fn (): array => DB::transaction(function () use ($type, $input, $ifMatch, $ifNoneMatch, $actor): array {
            $rows = $this->repository->rows();
            $stored = $this->codec->fromRows($rows);
            $this->assertPreconditions($stored, $rows, $ifMatch, $ifNoneMatch);

            $candidate = IdentityProviderConfig::fromInput($type, $input);
            $candidate = $candidate->withSecret($this->secrets->resolve($stored, $candidate, self::secretOf($candidate, $input)));

            if ($stored === null || $stored->type !== $type) {
                $this->assertNotInUse($type);
            }

            $this->write($candidate);
            $this->recordChange($stored, $candidate, $actor);

            return ['config' => $this->repository->current() ?? $candidate, 'created' => $stored === null];
        }));
    }

    /**
     * Changes the sent settings of the stored configuration (JSON Merge Patch).
     *
     * @param  array<string, mixed>  $patch  the sent members, `type` excluded
     */
    public function change(array $patch, ?string $ifMatch, Principal $actor): IdentityProviderConfig
    {
        return $this->lock->run([self::LOCK], fn (): IdentityProviderConfig => DB::transaction(function () use ($patch, $ifMatch, $actor): IdentityProviderConfig {
            $rows = $this->repository->rows();
            $stored = $this->codec->fromRows($rows) ?? throw new ProblemException(ProblemType::IdentityProviderNotConfigured);
            $this->assertPreconditions($stored, $rows, $ifMatch, null);

            $candidate = $stored->withPatch($patch);
            $candidate = $candidate->withSecret($this->secrets->resolve($stored, $candidate, self::secretOf($candidate, $patch)));

            $this->write($candidate);
            $this->recordChange($stored, $candidate, $actor);

            return $this->repository->current() ?? $candidate;
        }));
    }

    /**
     * Removes every stored setting, the cached service token included.
     */
    public function delete(?string $ifMatch, Principal $actor): void
    {
        $this->lock->run([self::LOCK], fn () => DB::transaction(function () use ($ifMatch, $actor): void {
            $rows = $this->repository->rows();
            $stored = $this->codec->fromRows($rows) ?? throw new ProblemException(ProblemType::IdentityProviderNotConfigured);
            $this->assertPreconditions($stored, $rows, $ifMatch, null);
            $this->assertNotInUse(null);

            DB::table('identity_provider')->delete();
            $this->audit->record(AuditEventType::IdentityProviderDeletion, true, $actor, self::target($stored->type), null, null, ['type' => $stored->type->value]);
        }));
    }

    /**
     * @param  array<string, string>  $rows
     */
    private function assertPreconditions(?IdentityProviderConfig $stored, array $rows, ?string $ifMatch, ?string $ifNoneMatch): void
    {
        $current = $stored === null ? null : $this->codec->etag($rows);

        if ($ifMatch !== null && trim($ifMatch) !== '' && ($current === null || ETag::preconditionFails($ifMatch, $current))) {
            throw new PreconditionFailedHttpException('The identity provider configuration was changed since it was read.');
        }

        // G20: `If-None-Match: *` creates only; a listed tag refuses a save over that version.
        if ($ifNoneMatch !== null && trim($ifNoneMatch) !== '' && $current !== null) {
            foreach (array_map('trim', explode(',', $ifNoneMatch)) as $tag) {
                if ($tag === '*' || $tag === $current) {
                    throw new PreconditionFailedHttpException('An identity provider configuration exists already.');
                }
            }
        }
    }

    private function assertNotInUse(?IdentityProviderType $newType): void
    {
        $counts = $this->usage->counts();
        $blocking = $this->usage->blocking($newType, $counts);

        if ($blocking > 0) {
            throw new ProblemException(
                ProblemType::IdentityProviderInUse,
                $blocking === 1
                    ? '1 mailbox still authenticates against an external identity provider.'
                    : "{$blocking} mailboxes still authenticate against an external identity provider.",
                ['blockingMailboxes' => $blocking, 'mailboxCounts' => $counts],
                [],
                ['mailboxCounts' => $counts],
            );
        }
    }

    /**
     * Writes the rows of the configuration as legacy does and removes every other row.
     */
    private function write(IdentityProviderConfig $config): void
    {
        $rows = $this->codec->toRows($config);

        DB::table('identity_provider')->whereNotIn('key', array_keys($rows))->delete();

        foreach ($rows as $key => $value) {
            DB::statement('INSERT INTO `identity_provider` (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $value]);
        }
    }

    private function recordChange(?IdentityProviderConfig $stored, IdentityProviderConfig $candidate, Principal $actor): void
    {
        $details = ['type' => $candidate->type->value, 'changes' => self::changes($stored, $candidate)];

        if ($stored !== null) {
            $details['previousType'] = $stored->type->value;
        }

        $this->audit->record(
            $stored === null ? AuditEventType::IdentityProviderCreation : AuditEventType::IdentityProviderUpdate,
            true,
            $actor,
            self::target($candidate->type),
            null,
            null,
            $details,
        );
    }

    /**
     * Each changed setting as `{old, new}` in contract names; secrets only as `{changed: true}`.
     *
     * @return array<string, mixed>|stdClass
     */
    private static function changes(?IdentityProviderConfig $stored, IdentityProviderConfig $candidate): array|stdClass
    {
        $old = $stored === null ? [] : $stored->settings;
        $new = $candidate->settings;
        $changes = [];

        foreach (array_unique([...array_keys($new), ...array_keys($old)]) as $member) {
            $before = $old[$member] ?? null;
            $after = $new[$member] ?? null;

            if ($before === $after) {
                continue;
            }

            $changes[$member] = in_array($member, ['clientSecret', 'bindPassword'], true) ? ['changed' => true] : ['old' => $before, 'new' => $after];
        }

        return $changes === [] ? new stdClass : $changes;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function secretOf(IdentityProviderConfig $candidate, array $input): ?string
    {
        $secret = $input[$candidate->secretMember()] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    /**
     * @return array{type: string, id: null, name: string}
     */
    public static function target(IdentityProviderType $type): array
    {
        return ['type' => 'identityProvider', 'id' => null, 'name' => $type->value];
    }
}
