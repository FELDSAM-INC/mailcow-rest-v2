<?php

namespace App\Services\Oauth2;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use App\Support\Http\ListQuery;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * The clients of mailcow's own OAuth2 provider (FR-041 to FR-049, research R13): every write to
 * `oauth_clients`, `oauth_access_tokens`, `oauth_refresh_tokens` and `oauth_authorization_codes`,
 * stored as legacy oauth2() stores them so that the legacy UI and the unchanged `/oauth/*`
 * endpoints use them. Each write runs in one transaction with its `If-Match` check and audit record;
 * refusals are thrown for the shared RefusalAuditor.
 *
 *  - Creation: a 12-hex client ID that no client has (regenerated on a collision; legacy refuses)
 *    and a 24-hex secret from a cryptographically secure source, scope `profile`, `grant_types` and
 *    `user_id` NULL.
 *  - Revocation and deletion remove the client's access tokens, refresh tokens and authorization
 *    codes. **Deviation**: legacy keeps the codes on revocation and every token on deletion.
 */
class Oauth2ClientService
{
    /** Container key of the client ID generator (a callable returning 12 hex characters). */
    public const CLIENT_ID_GENERATOR = 'restv2.oauth2.clientIdGenerator';

    public const SORTS = ['id' => 'id', 'clientId' => 'client_id', 'redirectUri' => 'redirect_uri'];

    public const REDIRECT_URIS_MAX_LENGTH = 2000;

    private const TOKEN_TABLES = [
        'accessTokens' => 'oauth_access_tokens',
        'refreshTokens' => 'oauth_refresh_tokens',
        'authorizationCodes' => 'oauth_authorization_codes',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, limit: int, offset: int}}
     */
    public function list(ListQuery $list, ?bool $hasActiveTokens): array
    {
        $query = DB::table('oauth_clients');
        $list->applySearch($query, ['client_id', 'redirect_uri']);

        if ($hasActiveTokens !== null) {
            $active = function ($q): void {
                $q->where(function ($inner): void {
                    foreach (['oauth_access_tokens', 'oauth_refresh_tokens'] as $table) {
                        $inner->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from($table)
                            ->whereColumn($table.'.client_id', 'oauth_clients.client_id')
                            ->whereRaw('`'.$table.'`.`expires` > NOW()'));
                    }
                });
            };
            $hasActiveTokens ? $active($query) : $query->whereNot($active);
        }

        $page = $list->paginate($query, null, 'id');
        $rows = array_values(array_filter($page['data'], fn (mixed $r): bool => $r instanceof stdClass));
        $counts = $this->counts(array_map(fn (stdClass $r): string => (string) $r->client_id, $rows));

        return [
            'data' => array_map(fn (stdClass $row): array => $this->present($row, $counts[(string) $row->client_id] ?? null), $rows),
            'meta' => $page['meta'],
        ];
    }

    public function find(int $id): ?stdClass
    {
        $row = DB::table('oauth_clients')->where('id', $id)->first();

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Contract `Oauth2Client` (without the secret).
     *
     * @param  array{accessTokens: int, refreshTokens: int}|null  $counts
     * @return array<string, mixed>
     */
    public function present(stdClass $row, ?array $counts = null): array
    {
        $counts ??= $this->counts([(string) $row->client_id])[(string) $row->client_id];

        return [
            'id' => (int) $row->id,
            'clientId' => (string) $row->client_id,
            'redirectUris' => self::splitUris($row->redirect_uri),
            'scope' => $row->scope === null ? null : (string) $row->scope,
            'activeAccessTokens' => $counts['accessTokens'],
            'activeRefreshTokens' => $counts['refreshTokens'],
            'etag' => self::etag($row),
        ];
    }

    /**
     * The version tag over the stored client, the secret only as an HMAC keyed with the app key
     * (FR-050, docs/conventions.md); tokens excluded.
     */
    public static function etag(stdClass $row): string
    {
        return ETag::of([
            (int) $row->id,
            (string) $row->client_id,
            hash_hmac('sha256', (string) $row->client_secret, (string) config('app.key')),
            $row->redirect_uri,
            $row->grant_types,
            $row->scope,
            $row->user_id,
        ]);
    }

    /**
     * @param  list<string>  $redirectUris  validated
     * @return array{row: stdClass, secret: string}
     */
    public function create(array $redirectUris, Principal $actor): array
    {
        return DB::transaction(function () use ($redirectUris, $actor): array {
            $clientId = $this->newClientId();
            $secret = bin2hex(random_bytes(12));
            $uris = self::normaliseUris($redirectUris);

            $id = (int) DB::table('oauth_clients')->insertGetId([
                'client_id' => $clientId,
                'client_secret' => $secret,
                'redirect_uri' => implode(' ', $uris),
                'grant_types' => null,
                'scope' => 'profile',
                'user_id' => null,
            ], 'id');

            $this->audit->record(AuditEventType::Oauth2ClientCreation, true, $actor, self::target($id, $clientId), null, null, ['redirectUris' => $uris]);

            return ['row' => $this->find($id) ?? throw new RuntimeException('The created client is gone.'), 'secret' => $secret];
        });
    }

    /**
     * @param  list<string>  $redirectUris  validated
     */
    public function updateRedirectUris(int $id, array $redirectUris, ?string $ifMatch, Principal $actor): stdClass
    {
        return DB::transaction(function () use ($id, $redirectUris, $ifMatch, $actor): stdClass {
            $row = $this->lock($id, $ifMatch);
            $old = self::splitUris($row->redirect_uri);
            $new = self::normaliseUris($redirectUris);

            DB::table('oauth_clients')->where('id', $id)->update(['redirect_uri' => implode(' ', $new)]);
            $this->audit->record(AuditEventType::Oauth2ClientUpdate, true, $actor, self::target($id, (string) $row->client_id), null, null, [
                'changes' => $old === $new ? new stdClass : ['redirectUris' => ['old' => $old, 'new' => $new]],
            ]);

            return $this->find($id) ?? throw new NotFoundHttpException('The OAuth2 client does not exist.');
        });
    }

    /**
     * @return array{row: stdClass, secret: string}
     */
    public function regenerateSecret(int $id, ?string $ifMatch, Principal $actor, ?string $bulkRequestId = null): array
    {
        return DB::transaction(function () use ($id, $ifMatch, $actor, $bulkRequestId): array {
            $row = $this->lock($id, $ifMatch);
            $secret = bin2hex(random_bytes(12));

            DB::table('oauth_clients')->where('id', $id)->update(['client_secret' => $secret]);
            $this->audit->record(AuditEventType::Oauth2ClientSecretRegeneration, true, $actor, self::target($id, (string) $row->client_id), null, null, self::withBulk([], $bulkRequestId));

            return ['row' => $this->find($id) ?? throw new NotFoundHttpException('The OAuth2 client does not exist.'), 'secret' => $secret];
        });
    }

    /**
     * Removes the client's access tokens, refresh tokens and authorization codes.
     *
     * @return array{accessTokens: int, refreshTokens: int, authorizationCodes: int}
     */
    public function revokeTokens(int $id, ?string $ifMatch, Principal $actor, ?string $bulkRequestId = null): array
    {
        return DB::transaction(function () use ($id, $ifMatch, $actor, $bulkRequestId): array {
            $row = $this->lock($id, $ifMatch);
            $removed = $this->removeTokens((string) $row->client_id);

            $this->audit->record(AuditEventType::Oauth2ClientTokenRevocation, true, $actor, self::target($id, (string) $row->client_id), null, null, self::withBulk(['removed' => $removed], $bulkRequestId));

            return $removed;
        });
    }

    /**
     * Removes the client together with its tokens and codes.
     *
     * @return array{accessTokens: int, refreshTokens: int, authorizationCodes: int}
     */
    public function delete(int $id, ?string $ifMatch, Principal $actor, ?string $bulkRequestId = null): array
    {
        return DB::transaction(function () use ($id, $ifMatch, $actor, $bulkRequestId): array {
            $row = $this->lock($id, $ifMatch);
            $removed = $this->removeTokens((string) $row->client_id);
            DB::table('oauth_clients')->where('id', $id)->delete();

            $this->audit->record(AuditEventType::Oauth2ClientDeletion, true, $actor, self::target($id, (string) $row->client_id), null, null, self::withBulk(['removed' => $removed], $bulkRequestId));

            return $removed;
        });
    }

    /**
     * The client's redirect URIs as stored: split on spaces, empty parts dropped (the way the OAuth2
     * server library splits several registered URIs).
     *
     * @return list<string>
     */
    public static function splitUris(mixed $stored): array
    {
        return array_values(array_filter(explode(' ', (string) $stored), fn (string $u): bool => $u !== ''));
    }

    /**
     * Duplicates removed, first position kept.
     *
     * @param  list<string>  $uris
     * @return list<string>
     */
    public static function normaliseUris(array $uris): array
    {
        return array_values(array_unique(array_map('strval', $uris)));
    }

    /**
     * @return array{type: string, id: string, name: string}
     */
    public static function target(int $id, string $clientId): array
    {
        return ['type' => 'oauth2Client', 'id' => (string) $id, 'name' => $clientId];
    }

    private function lock(int $id, ?string $ifMatch): stdClass
    {
        $row = DB::table('oauth_clients')->where('id', $id)->lockForUpdate()->first();

        if (! $row instanceof stdClass) {
            throw new NotFoundHttpException('The OAuth2 client does not exist.');
        }

        if (ETag::preconditionFails($ifMatch, self::etag($row))) {
            throw new PreconditionFailedHttpException('The OAuth2 client was changed since it was read.');
        }

        return $row;
    }

    /**
     * @return array{accessTokens: int, refreshTokens: int, authorizationCodes: int}
     */
    private function removeTokens(string $clientId): array
    {
        $removed = [];

        foreach (self::TOKEN_TABLES as $key => $table) {
            $removed[$key] = DB::table($table)->where('client_id', $clientId)->delete();
        }

        /** @var array{accessTokens: int, refreshTokens: int, authorizationCodes: int} $removed */
        return $removed;
    }

    /**
     * Unexpired access and refresh tokens per client ID, two grouped queries for a page.
     *
     * @param  list<string>  $clientIds
     * @return array<string, array{accessTokens: int, refreshTokens: int}>
     */
    private function counts(array $clientIds): array
    {
        $counts = array_fill_keys($clientIds, ['accessTokens' => 0, 'refreshTokens' => 0]);

        if ($clientIds === []) {
            return $counts;
        }

        foreach (['accessTokens' => 'oauth_access_tokens', 'refreshTokens' => 'oauth_refresh_tokens'] as $key => $table) {
            $rows = DB::table($table)->whereIn('client_id', $clientIds)->whereRaw('`expires` > NOW()')
                ->groupBy('client_id')->selectRaw('`client_id`, COUNT(*) AS `n`')->get();

            foreach ($rows as $row) {
                if (isset($counts[(string) $row->client_id])) {
                    $counts[(string) $row->client_id][$key] = (int) $row->n;
                }
            }
        }

        return $counts;
    }

    private function newClientId(): string
    {
        /** @var callable(): string $generate */
        $generate = app(self::CLIENT_ID_GENERATOR);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $clientId = (string) $generate();

            if (! DB::table('oauth_clients')->where('client_id', $clientId)->exists()) {
                return $clientId;
            }
        }

        throw new RuntimeException('No unused OAuth2 client ID was generated.');
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private static function withBulk(array $details, ?string $bulkRequestId): array
    {
        return $bulkRequestId === null ? $details : $details + ['bulkRequestId' => $bulkRequestId];
    }
}
