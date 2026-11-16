<?php

namespace App\Services\SyncSources;

use App\Auth\Principal;
use App\Services\SyncJobs\SyncJobEncryption;
use App\Support\Http\ETag;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SourceReader
{
    public function __construct(private readonly SourceScope $scope) {}

    public function row(int $id, Principal $actor, bool $lock = false): object
    {
        $q = $this->scope->query($actor)->where('s.id', $id);

        return ($lock ? $q->lockForUpdate() : $q)->first() ?? throw new NotFoundHttpException;
    }

    /** @return array{domains: list<string>, users: list<string>} */
    public function targets(int $id): array
    {
        return ['domains' => DB::table('imapsync_source_domain')->where('source_id', $id)->orderBy('domain')->pluck('domain')->all(),
            'users' => DB::table('imapsync_source_user')->where('source_id', $id)->orderBy('username')->pluck('username')->all()];
    }

    /** @param array{domains: list<string>, users: list<string>}|null $targets */
    public function version(object $row, ?array $targets = null): string
    {
        $values = (array) $row;
        // Cron token rotation does not invalidate configuration edits; credential/config changes do.
        foreach (['oauth_access_token', 'oauth_token_expires', 'oauth_last_refresh_error', 'oauth_last_refresh_attempt', 'modified'] as $key) {
            unset($values[$key]);
        }

        return ETag::of([$values, $targets ?? $this->targets((int) $row->id)]);
    }

    /** @return array<string, mixed> */
    public function present(object $r, Principal $actor): array
    {
        $editable = $this->scope->editable($r, $actor);
        $targets = $this->targets((int) $r->id);

        return ['id' => (int) $r->id, 'name' => $r->name, 'description' => $r->description, 'createdBy' => $r->created_by,
            'scope' => $r->scope, ...$targets, 'remoteHost' => $r->host1, 'remotePort' => (int) $r->port1,
            'encryption' => SyncJobEncryption::tryFrom($r->enc1 ?? 'TLS')?->toApi() ?? 'starttls',
            'verifyCertificate' => (bool) $r->verify_cert, 'authMechanism' => strtolower($r->auth_type ?? 'PLAIN'),
            'active' => (bool) $r->active, 'editable' => $editable,
            'oauth' => $r->auth_type !== 'XOAUTH2' ? null : [
                'flow' => $r->oauth_flow, 'tokenEndpoint' => $r->oauth_token_endpoint, 'authorizationEndpoint' => $r->oauth_authorize_endpoint,
                'userInfoEndpoint' => $r->oauth_userinfo_endpoint, 'clientId' => $r->oauth_client_id, 'scope' => $r->oauth_scope,
                'extraParameters' => $editable ? (object) (json_decode($r->oauth_extra_params ?? '{}', true) ?? []) : null,
                'clientSecretSet' => (string) $r->oauth_client_secret !== '', 'tokenSet' => (string) $r->oauth_access_token !== '',
                'tokenExpiresAt' => $r->oauth_token_expires === null ? null : CarbonImmutable::createFromTimestampUTC((int) $r->oauth_token_expires)->toIso8601ZuluString(),
                // Legacy errors can contain provider-supplied text; never return it to clients.
                'refreshFailed' => (string) $r->oauth_last_refresh_error !== '',
                'redirectUri' => SourceOAuth::redirectUri(),
            ], 'etag' => $this->version($r, $targets)];
    }
}
