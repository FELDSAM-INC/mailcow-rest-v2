<?php

namespace App\Clients\Oidc;

use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Middleware;
use Illuminate\Support\Facades\Http;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * OidcClient on legacy's own OAuth 2.0 client library (`league/oauth2-client`, research R1): the
 * GenericProvider for Keycloak and generic OIDC, PKCE with S256, client credentials in the form body,
 * the scopes separated by spaces. The Guzzle client runs on Laravel's HTTP client handler stack, so
 * Http::fake() and Http::assertSent() see every call; it times out after the configured seconds,
 * verifies TLS unless the configuration ignores TLS errors, and never follows redirects (FR-056).
 *
 * Failures are classified from the last answer: no answer or a server error is `unavailable`,
 * anything else `refused`.
 */
class LeagueOidcClient implements OidcClient
{
    public function authorizationUrl(OidcEndpoints $endpoints, string $returnUrl, string $state): array
    {
        $provider = $this->provider($endpoints, $returnUrl, new LastResponse);
        $url = $provider->getAuthorizationUrl(['state' => $state]);

        return ['url' => $url, 'codeVerifier' => (string) $provider->getPkceCode()];
    }

    public function exchangeCode(OidcEndpoints $endpoints, string $code, string $codeVerifier, string $returnUrl): OidcTokens
    {
        $last = new LastResponse;
        $provider = $this->provider($endpoints, $returnUrl, $last);
        $provider->setPkceCode($codeVerifier);

        return $this->call($last, fn (): OidcTokens => self::tokens($provider->getAccessToken('authorization_code', ['code' => $code])));
    }

    public function refresh(OidcEndpoints $endpoints, string $refreshToken): OidcTokens
    {
        $last = new LastResponse;
        $provider = $this->provider($endpoints, null, $last);

        return $this->call($last, fn (): OidcTokens => self::tokens($provider->getAccessToken('refresh_token', ['refresh_token' => $refreshToken])));
    }

    public function userInfo(OidcEndpoints $endpoints, string $accessToken): array
    {
        $last = new LastResponse;
        $provider = $this->provider($endpoints, null, $last);

        return $this->call($last, function () use ($provider, $accessToken, $last): array {
            $details = $provider->getResourceOwner(new AccessToken(['access_token' => $accessToken]))->toArray();

            if (($last->status ?? 200) >= 300) {
                throw OidcFailure::refused($last->status);
            }

            return $details;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function call(LastResponse $last, Closure $call): mixed
    {
        try {
            return $call();
        } catch (OidcFailure $e) {
            throw $e;
        } catch (ConnectException $e) {
            throw OidcFailure::unavailable(null, self::curlErrno($e));
        } catch (TransferException $e) {
            throw $last->status !== null && $last->status < 500
                ? OidcFailure::refused($last->status)
                : OidcFailure::unavailable($last->status, self::curlErrno($e));
        } catch (Throwable $e) {
            if ($last->status === null || $last->status >= 500) {
                throw OidcFailure::unavailable($last->status);
            }

            throw OidcFailure::refused($last->status, $e instanceof IdentityProviderException ? substr($e->getMessage(), 0, 64) : null);
        }
    }

    /**
     * @param  LastResponse  $last  receives the status of the last answer
     */
    private function provider(OidcEndpoints $endpoints, ?string $returnUrl, LastResponse $last): GenericProvider
    {
        $timeout = (int) config('restv2.identity_provider.timeout', 7);
        $stack = Http::withOptions([])->buildHandlerStack();
        // Outermost, so that it also sees the answers of Http::fake().
        $stack->unshift(Middleware::mapResponse(function (ResponseInterface $response) use ($last): ResponseInterface {
            $last->status = $response->getStatusCode();

            return $response;
        }));

        $client = new Client([
            'handler' => $stack,
            'timeout' => $timeout,
            'connect_timeout' => $timeout,
            'verify' => ! $endpoints->ignoreTlsErrors,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);

        return new GenericProvider([
            'clientId' => $endpoints->clientId,
            'clientSecret' => $endpoints->clientSecret,
            'redirectUri' => $returnUrl,
            'urlAuthorize' => $endpoints->authorization,
            'urlAccessToken' => $endpoints->token,
            'urlResourceOwnerDetails' => $endpoints->userInfo,
            'scopes' => $endpoints->scopes,
            'scopeSeparator' => ' ',
            'pkceMethod' => GenericProvider::PKCE_METHOD_S256,
        ], ['httpClient' => $client]);
    }

    private static function tokens(mixed $token): OidcTokens
    {
        if (! $token instanceof AccessToken) {
            throw OidcFailure::refused(null);
        }

        $refresh = $token->getRefreshToken();

        return new OidcTokens($token->getToken(), $refresh === null || $refresh === '' ? null : $refresh, $token->getExpires());
    }

    private static function curlErrno(Throwable $e): ?int
    {
        return preg_match('/cURL error (\d+)/', $e->getMessage(), $m) === 1 ? (int) $m[1] : null;
    }
}
