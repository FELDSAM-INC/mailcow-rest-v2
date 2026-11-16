<?php

namespace App\Services\SyncSources;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\SyncSources\OAuthFailure;
use App\Clients\SyncSources\OAuthHttpClient;
use App\Http\RequestContext;
use App\Services\Audit\AuditLogger;
use App\Services\IdentityProvider\AppReturnUrls;
use App\Support\Database\NamedLock;
use App\Support\Http\Base64Url;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class SourceOAuth
{
    private const PENDING = 'restv2.sync-source-oauth';

    private const AUTHORIZE_PARAMS = ['prompt', 'access_type', 'include_granted_scopes', 'login_hint', 'domain_hint'];

    public function __construct(private readonly SourceScope $scope, private readonly SourceReader $reader,
        private readonly SourceService $sources, private readonly OAuthHttpClient $http, private readonly NamedLock $locks,
        private readonly AuditLogger $audit) {}

    /** One registered callback works with both /ng/ and root routing; never derive it from untrusted input. */
    public static function redirectUri(): string
    {
        $sso = app(AppReturnUrls::class)->all()[0] ?? null;
        if (! is_string($sso)) {
            throw new ServiceUnavailableHttpException(null, 'The mailcow host name is not configured.');
        }

        return preg_replace('~/sso/return$~', '/sync-sources/oauth/return', $sso) ?? $sso;
    }

    /** @return array{authorizationUrl: string, expiresAt: string} */
    public function start(Request $request, int $id, string $owner): array
    {
        $actor = RequestContext::requirePrincipal($request);
        $source = $this->connectable($id, $owner, $actor);
        if ($actor->apiKey !== null) {
            throw new AccessDeniedHttpException('Connect OAuth accounts from a browser session.');
        }
        $state = Base64Url::encode(random_bytes(32));
        $verifier = Base64Url::encode(random_bytes(48));
        try {
            $this->http->pin($source->oauth_authorize_endpoint ?? '', SourceValidator::publicOnly($source->created_by));
        } catch (OAuthFailure) {
            throw new ConflictHttpException('The authorization endpoint is unavailable.');
        }
        $expires = now()->addMinutes(10);
        $request->session()->put(self::PENDING, [
            'stateHash' => hash('sha256', $state), 'verifier' => $verifier, 'sourceId' => $id,
            'version' => $this->reader->version($source), 'owner' => $owner, 'actor' => $actor->username,
            'accountCreatedAt' => $actor->accountCreatedAt, 'expiresAt' => $expires->getTimestamp(),
        ]);
        $params = array_merge(['prompt' => 'select_account'], $this->extra($source, true), [
            'client_id' => $source->oauth_client_id, 'response_type' => 'code', 'redirect_uri' => self::redirectUri(),
            'response_mode' => 'query', 'scope' => $source->oauth_scope, 'state' => $state,
            'code_challenge_method' => 'S256', 'code_challenge' => Base64Url::encode(hash('sha256', $verifier, true)),
        ]);

        return ['authorizationUrl' => $source->oauth_authorize_endpoint.(str_contains($source->oauth_authorize_endpoint, '?') ? '&' : '?').http_build_query($params),
            'expiresAt' => $expires->toIso8601ZuluString()];
    }

    /** @return array{sourceId: int, mailbox: string, remoteUser: string} */
    public function complete(Request $request, string $state, string $code): array
    {
        $actor = RequestContext::requirePrincipal($request);
        if ($actor->apiKey !== null) {
            throw new AccessDeniedHttpException;
        }
        $pending = $request->session()->pull(self::PENDING);
        if (! is_array($pending) || ($pending['expiresAt'] ?? 0) <= now()->getTimestamp()
            || ! hash_equals($pending['stateHash'] ?? '', hash('sha256', $state))
            || ($pending['actor'] ?? null) !== $actor->username || ($pending['accountCreatedAt'] ?? null) !== $actor->accountCreatedAt
            || ! Cache::add('sync-source-oauth-used:'.$pending['stateHash'], true, 660)) {
            throw new ConflictHttpException('The OAuth connection request expired or was already used.');
        }

        return $this->locks->run(['restv2_sync_sources'], function () use ($actor, $pending, $code): array {
            $source = $this->connectable($pending['sourceId'], $pending['owner'], $actor);
            if (! hash_equals($pending['version'], $this->reader->version($source))) {
                throw new ConflictHttpException('The source changed; start the connection again.');
            }
            try {
                $token = $this->token($source, ['grant_type' => 'authorization_code', 'code' => $code,
                    'redirect_uri' => self::redirectUri(), 'code_verifier' => $pending['verifier']]);
                $identity = $this->identity($source, $token);
                if ($identity === null) {
                    throw new OAuthFailure('identityUnavailable');
                }
            } catch (OAuthFailure) {
                throw new ServiceUnavailableHttpException(null, 'The OAuth account could not be connected.');
            }
            DB::transaction(function () use ($source, $actor, $pending, $identity, $token): void {
                $current = $this->reader->row((int) $source->id, $actor, true);
                if (! hash_equals($pending['version'], $this->reader->version($current))) {
                    throw new ConflictHttpException('The source changed; start the connection again.');
                }
                DB::table('imapsync_source_oauth_token')->updateOrInsert(
                    ['source_id' => $source->id, 'owner' => $pending['owner'], 'username' => $identity],
                    ['access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'] ?? '',
                        'token_expires' => time() + $token['expires_in'], 'last_refresh_error' => null, 'oauth_last_refresh_attempt' => now()]);
                $this->audit->record(AuditEventType::SyncSourceConnection, true, $actor, ['type' => 'syncSource', 'id' => (string) $source->id],
                    null, null, ['mailbox' => $pending['owner'], 'operation' => 'connect']);
            });

            return ['sourceId' => (int) $source->id, 'mailbox' => $pending['owner'], 'remoteUser' => $identity];
        }, 25);
    }

    /** @return list<array<string, mixed>> */
    public function connections(int $id, string $owner, Principal $actor): array
    {
        $this->connectable($id, $owner, $actor, false);

        return DB::table('imapsync_source_oauth_token')->where('source_id', $id)->where('owner', $owner)->orderBy('username')
            ->get(['username', 'token_expires', 'last_refresh_error'])->map(fn ($t): array => ['remoteUser' => $t->username,
                'expiresAt' => $t->token_expires === null ? null : CarbonImmutable::createFromTimestampUTC((int) $t->token_expires)->toIso8601ZuluString(),
                'refreshFailed' => (string) $t->last_refresh_error !== ''])->all();
    }

    public function disconnect(int $id, string $owner, string $remote, Principal $actor): void
    {
        $this->connectable($id, $owner, $actor, false);
        if ($actor->apiKey?->accessLevel === 'readOnly') {
            throw new AccessDeniedHttpException;
        }
        DB::transaction(function () use ($id, $owner, $remote, $actor): void {
            DB::table('imapsync_source_oauth_token')->where('source_id', $id)->where('owner', $owner)->where('username', $remote)->delete();
            $this->audit->record(AuditEventType::SyncSourceConnection, true, $actor, ['type' => 'syncSource', 'id' => (string) $id], null, null,
                ['mailbox' => $owner, 'operation' => 'disconnect']);
        });
    }

    /** @return array<string, mixed> */
    public function refresh(int $id, Principal $actor, ?string $ifMatch): array
    {
        $this->scope->authorize($actor);

        return $this->locks->run(['restv2_sync_sources'], function () use ($id, $actor, $ifMatch): array {
            $source = $this->reader->row($id, $actor);
            $this->sources->assertEditable($source, $actor, $ifMatch);
            if ($source->auth_type !== 'XOAUTH2' || $source->oauth_flow !== 'client_credentials' || ! $source->active) {
                throw new ConflictHttpException('This source has no active shared token.');
            }
            $version = $this->reader->version($source);
            try {
                $token = $this->token($source, ['grant_type' => 'client_credentials']);
                $update = ['oauth_access_token' => $token['access_token'], 'oauth_token_expires' => time() + $token['expires_in'], 'oauth_last_refresh_error' => null];
            } catch (OAuthFailure $e) {
                $update = ['oauth_last_refresh_error' => $e->getMessage()];
            }
            DB::transaction(function () use ($id, $actor, $version, $update): void {
                $current = $this->reader->row($id, $actor, true);
                if (! hash_equals($version, $this->reader->version($current))) {
                    throw new ConflictHttpException('The source changed during refresh.');
                }
                DB::table('imapsync_source')->where('id', $id)->update($update + ['oauth_last_refresh_attempt' => now()]);
                $this->audit->record(AuditEventType::SyncSourceConnection, ! isset($update['oauth_last_refresh_error']), $actor,
                    ['type' => 'syncSource', 'id' => (string) $id], null, null, ['operation' => 'refresh']);
            });

            return $this->reader->present($this->reader->row($id, $actor), $actor);
        }, 25);
    }

    private function connectable(int $id, string $owner, Principal $actor, bool $active = true): object
    {
        $this->scope->authorize($actor);
        if (! $this->scope->mailbox($actor, $owner)) {
            throw new NotFoundHttpException;
        }
        $source = $this->reader->row($id, $actor);
        if ($source->auth_type !== 'XOAUTH2' || $source->oauth_flow !== 'authorization_code' || ($active && ! $source->active)) {
            throw new ConflictHttpException('This source cannot connect OAuth accounts.');
        }

        return $source;
    }

    /** @return array<string, scalar|null> */
    private function extra(object $s, bool $authorize): array
    {
        $extra = json_decode($s->oauth_extra_params ?? '{}', true);
        if (! is_array($extra)) {
            return [];
        }

        return array_filter($extra, fn ($value, $key): bool => is_string($key) && (is_scalar($value) || $value === null)
            && in_array($key, self::AUTHORIZE_PARAMS, true) === $authorize, ARRAY_FILTER_USE_BOTH);
    }

    /** @param array<string, string> $grant
     * @return array<string, mixed>
     */
    private function token(object $s, array $grant): array
    {
        $params = array_merge($this->extra($s, false), ['client_id' => $s->oauth_client_id, 'client_secret' => $s->oauth_client_secret, 'scope' => $s->oauth_scope], $grant);
        $token = $this->http->request($s->oauth_token_endpoint ?? '', SourceValidator::publicOnly($s->created_by), $params);
        if (! is_string($token['access_token'] ?? null) || $token['access_token'] === '') {
            throw new OAuthFailure('malformedResponse');
        }
        $token['expires_in'] = is_numeric($token['expires_in'] ?? null) ? max(0, min(31536000, (int) $token['expires_in'])) : 3600;
        if (! is_string($token['refresh_token'] ?? null)) {
            unset($token['refresh_token']);
        }

        return $token;
    }

    /**
     * Token-endpoint claims are received directly over pinned TLS, not accepted as login credentials.
     *
     * @param  array<string, mixed>  $token
     */
    private function identity(object $s, array $token): ?string
    {
        $parts = explode('.', is_string($token['id_token'] ?? null) ? $token['id_token'] : '');
        $claims = count($parts) === 3 ? json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) : [];
        if (! is_array($claims)) {
            $claims = [];
        }
        foreach (['email', 'preferred_username', 'upn', 'unique_name'] as $key) {
            if (is_string($claims[$key] ?? null) && filter_var($claims[$key], FILTER_VALIDATE_EMAIL)) {
                return strtolower($claims[$key]);
            }
        }
        if ((string) $s->oauth_userinfo_endpoint === '') {
            return null;
        }
        $claims = $this->http->request($s->oauth_userinfo_endpoint, SourceValidator::publicOnly($s->created_by), null, $token['access_token']);
        foreach (['email', 'preferred_username', 'upn', 'unique_name'] as $key) {
            if (is_string($claims[$key] ?? null) && filter_var($claims[$key], FILTER_VALIDATE_EMAIL)) {
                return strtolower($claims[$key]);
            }
        }

        return null;
    }
}
