<?php

namespace App\Clients\Keycloak;

use App\Clients\Oidc\OidcFailure;
use App\Services\IdentityProvider\IdentityProviderConfig;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * KeycloakAdminClient on Laravel's HTTP client (research R3), a port of legacy
 * identity_provider('get-keycloak-admin-token') and keycloak_mbox_login_rest().
 */
class HttpKeycloakAdminClient implements KeycloakAdminClient
{
    public function introspect(IdentityProviderConfig $config, #[\SensitiveParameter] string $token, #[\SensitiveParameter] ?string $secret = null): bool
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::realmBase($config).'/protocol/openid-connect/token/introspect', [
            'token' => $token,
            'client_id' => (string) $config->get('clientId'),
            'client_secret' => $secret ?? (string) $config->secret(),
        ]), $config);

        if ($response->serverError()) {
            throw OidcFailure::unavailable($response->status());
        }

        return $response->status() === 200 && ($response->json('active') === true);
    }

    public function serviceToken(IdentityProviderConfig $config, #[\SensitiveParameter] ?string $secret = null): string
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::realmBase($config).'/protocol/openid-connect/token', [
            'grant_type' => 'client_credentials',
            'client_id' => (string) $config->get('clientId'),
            'client_secret' => $secret ?? (string) $config->secret(),
        ]), $config);

        $token = $response->status() === 200 ? $response->json('access_token') : null;

        if (! is_string($token) || $token === '') {
            $error = $response->json('error');

            throw $response->serverError()
                ? OidcFailure::unavailable($response->status())
                : OidcFailure::refused($response->status(), is_string($error) ? substr($error, 0, 64) : null);
        }

        return $token;
    }

    public function findUsersByEmail(IdentityProviderConfig $config, #[\SensitiveParameter] string $token, string $email): array
    {
        $url = rtrim((string) $config->get('serverUrl'), '/').'/admin/realms/'.(string) $config->get('realm').'/users';
        $response = $this->send(fn (PendingRequest $http): Response => $http->withToken($token)->acceptJson()->get($url, ['email' => $email, 'exact' => 'true']), $config);

        if ($response->status() !== 200) {
            throw $response->serverError() ? OidcFailure::unavailable($response->status()) : OidcFailure::refused($response->status());
        }

        $users = $response->json();

        return is_array($users) ? array_values(array_filter($users, 'is_array')) : [];
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, IdentityProviderConfig $config): Response
    {
        $timeout = (int) config('restv2.identity_provider.timeout', 7);

        try {
            return $call(Http::timeout($timeout)->connectTimeout($timeout)->withOptions([
                'verify' => ! $config->get('ignoreTlsErrors'),
                'allow_redirects' => false,
            ]));
        } catch (ConnectionException $e) {
            throw OidcFailure::unavailable(null, preg_match('/cURL error (\d+)/', $e->getMessage(), $m) === 1 ? (int) $m[1] : null);
        }
    }

    private static function realmBase(IdentityProviderConfig $config): string
    {
        return rtrim((string) $config->get('serverUrl'), '/').'/realms/'.(string) $config->get('realm');
    }
}
