<?php

namespace App\Services\SyncSources;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Services\SyncJobs\SyncJobEncryption;
use App\Support\Database\NamedLock;
use App\Support\Http\ETag;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

final class SourceService
{
    public const COLUMNS = ['name' => 'name', 'description' => 'description', 'scope' => 'scope', 'remoteHost' => 'host1',
        'remotePort' => 'port1', 'encryption' => 'enc1', 'verifyCertificate' => 'verify_cert', 'authMechanism' => 'auth_type', 'active' => 'active'];

    public const OAUTH = ['flow' => 'oauth_flow', 'tokenEndpoint' => 'oauth_token_endpoint', 'authorizationEndpoint' => 'oauth_authorize_endpoint',
        'userInfoEndpoint' => 'oauth_userinfo_endpoint', 'clientId' => 'oauth_client_id', 'clientSecret' => 'oauth_client_secret',
        'scope' => 'oauth_scope', 'extraParameters' => 'oauth_extra_params'];

    private const DEFAULTS = ['description' => '', 'scope' => 'user', 'enc1' => 'TLS', 'verify_cert' => 1, 'auth_type' => 'PLAIN',
        'active' => 1, 'oauth_flow' => 'client_credentials', 'oauth_token_endpoint' => null, 'oauth_authorize_endpoint' => null,
        'oauth_userinfo_endpoint' => null, 'oauth_client_id' => null, 'oauth_client_secret' => null, 'oauth_scope' => null, 'oauth_extra_params' => null];

    private const CONNECTION = ['host1', 'port1', 'enc1', 'auth_type', 'oauth_flow', 'oauth_token_endpoint', 'oauth_authorize_endpoint',
        'oauth_userinfo_endpoint', 'oauth_client_id', 'oauth_client_secret', 'oauth_scope', 'oauth_extra_params'];

    public function __construct(private readonly SourceScope $scope, private readonly SourceReader $reader,
        private readonly SourceValidator $validator, private readonly NamedLock $locks, private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function save(?int $id, array $patch, Principal $actor, ?string $ifMatch): array
    {
        $this->scope->authorize($actor);
        if ($actor->apiKey?->accessLevel === 'readOnly') {
            throw new AccessDeniedHttpException;
        }

        return $this->locks->run(['restv2_sync_sources'], function () use ($id, $patch, $actor, $ifMatch): array {
            try {
                $saved = DB::transaction(function () use ($id, $patch, $actor, $ifMatch): int {
                    $old = $id === null ? null : $this->reader->row($id, $actor, true);
                    if ($old !== null) {
                        $this->assertEditable($old, $actor, $ifMatch);
                    }
                    $values = array_intersect_key((array) $old + self::DEFAULTS, array_flip([...array_values(self::COLUMNS), ...array_values(self::OAUTH)]));
                    $values['created_by'] = $old->created_by ?? $actor->username;
                    foreach (self::COLUMNS as $field => $column) {
                        if (! array_key_exists($field, $patch)) {
                            continue;
                        }
                        $value = $patch[$field];
                        $values[$column] = match ($field) {
                            'encryption' => SyncJobEncryption::fromApi($value)->value,
                            'authMechanism' => strtoupper($value), 'remoteHost' => strtolower(trim($value)),
                            'name' => trim($value), default => is_bool($value) ? (int) $value : $value,
                        };
                    }
                    foreach (self::OAUTH as $field => $column) {
                        if (! array_key_exists($field, $patch['oauth'] ?? []) || ($field === 'clientSecret' && $patch['oauth'][$field] === '')) {
                            continue;
                        }
                        $values[$column] = $field === 'extraParameters' ? json_encode((object) $patch['oauth'][$field], JSON_THROW_ON_ERROR) : $patch['oauth'][$field];
                    }
                    $targets = $id === null ? ['domains' => [], 'users' => []] : $this->reader->targets($id);
                    foreach (['domains', 'users'] as $key) {
                        if (array_key_exists($key, $patch)) {
                            $targets[$key] = array_values(array_unique(array_map(fn ($v) => strtolower(trim($v)), $patch[$key])));
                        }
                        if (($key === 'domains' && $values['scope'] !== 'domain') || ($key === 'users' && $values['scope'] !== 'user')) {
                            $targets[$key] = [];
                        }
                        sort($targets[$key]);
                    }
                    $this->validator->validate($values, $targets, $old, $patch, $actor);
                    $changed = $old !== null && array_filter(self::CONNECTION, fn ($key) => (string) $old->$key !== (string) $values[$key]) !== [];
                    if ($old !== null && ($changed || ((bool) $old->verify_cert && ! $values['verify_cert'])) && ! $this->scope->allJobsAccessible($id, $actor)) {
                        throw new ConflictHttpException('This source is used by mailboxes outside your scope.');
                    }
                    // A saved password belongs to its original remote endpoint. Source management
                    // must not bypass the job editor's password re-entry requirement.
                    $redirectsPassword = $old !== null && array_filter(['host1', 'port1', 'enc1', 'auth_type'],
                        fn ($key) => (string) $old->$key !== (string) $values[$key]) !== [];
                    $weakensTls = $old !== null && (bool) $old->verify_cert && ! $values['verify_cert'];
                    if (($redirectsPassword || $weakensTls) && DB::table('imapsync')->where('source_id', $id)->where('password1', '<>', '')->exists()) {
                        throw new ConflictHttpException('Create a new source and select it on each job with a newly entered password.');
                    }
                    if ($changed) {
                        $values += ['oauth_access_token' => null, 'oauth_token_expires' => null, 'oauth_last_refresh_error' => null, 'oauth_last_refresh_attempt' => null];
                        DB::table('imapsync_source_oauth_token')->where('source_id', $id)->delete();
                    }
                    $saved = $id ?? (int) DB::table('imapsync_source')->insertGetId($values);
                    if ($id !== null) {
                        DB::table('imapsync_source')->where('id', $id)->update($values);
                    }
                    foreach (['domains' => ['domain', 'domain'], 'users' => ['user', 'username']] as $key => [$table, $column]) {
                        DB::table('imapsync_source_'.$table)->where('source_id', $saved)->delete();
                        foreach (array_chunk($targets[$key], 500) as $chunk) {
                            DB::table('imapsync_source_'.$table)->insert(array_map(fn ($target) => ['source_id' => $saved, $column => $target], $chunk));
                        }
                    }
                    $this->audit->record($id === null ? AuditEventType::SyncSourceCreation : AuditEventType::SyncSourceUpdate, true, $actor,
                        ['type' => 'syncSource', 'id' => (string) $saved], null, null, ['fields' => array_keys($patch)]);

                    return $saved;
                });
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    throw new ConflictHttpException('A source with this name already exists.', $e);
                }
                throw $e;
            }

            return $this->reader->present($this->reader->row($saved, $actor), $actor);
        });
    }

    public function delete(int $id, Principal $actor, ?string $ifMatch): void
    {
        $this->scope->authorize($actor);
        $this->locks->run(['restv2_sync_sources'], fn () => DB::transaction(function () use ($id, $actor, $ifMatch): void {
            $row = $this->reader->row($id, $actor, true);
            $this->assertEditable($row, $actor, $ifMatch);
            if (DB::table('imapsync')->where('source_id', $id)->exists()) {
                throw new ConflictHttpException('This source is still used by sync jobs.');
            }
            DB::table('imapsync_source')->where('id', $id)->delete();
            $this->audit->record(AuditEventType::SyncSourceDeletion, true, $actor, ['type' => 'syncSource', 'id' => (string) $id], null, null, []);
        }));
    }

    public function assertEditable(object $row, Principal $actor, ?string $ifMatch): void
    {
        if (! $this->scope->editable($row, $actor)) {
            throw new AccessDeniedHttpException;
        }
        if (ETag::preconditionFails($ifMatch, $this->reader->version($row))) {
            throw new PreconditionFailedHttpException;
        }
    }
}
