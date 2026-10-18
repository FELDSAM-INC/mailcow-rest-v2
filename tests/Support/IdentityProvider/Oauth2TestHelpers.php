<?php

namespace Tests\Support\IdentityProvider;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Helpers of feature 012's OAuth2 client tests.
 */
trait Oauth2TestHelpers
{
    use IdentityProviderTestHelpers;

    /**
     * A client with one unexpired and one expired token of each kind and an authorization code.
     *
     * @return array{id: int, clientId: string}
     */
    protected function clientWithTokens(string $redirectUri = 'https://wiki.example.org/oauth/callback'): array
    {
        $id = $this->mailcow()->oauthClient($redirectUri);
        $clientId = (string) DB::table('oauth_clients')->where('id', $id)->value('client_id');
        $future = CarbonImmutable::now()->addHour();
        $past = CarbonImmutable::now()->subHour();

        $this->mailcow()->oauthAccessToken($clientId, $future);
        $this->mailcow()->oauthAccessToken($clientId, $past);
        $this->mailcow()->oauthRefreshToken($clientId, $future);
        $this->mailcow()->oauthRefreshToken($clientId, $past);
        $this->mailcow()->oauthAuthorizationCode($clientId, $future);

        return ['id' => $id, 'clientId' => $clientId];
    }

    /**
     * Rows per token table of a client.
     *
     * @return array{accessTokens: int, refreshTokens: int, authorizationCodes: int}
     *
     * @phpstan-impure
     */
    protected function tokenRows(string $clientId): array
    {
        return [
            'accessTokens' => DB::table('oauth_access_tokens')->where('client_id', $clientId)->count(),
            'refreshTokens' => DB::table('oauth_refresh_tokens')->where('client_id', $clientId)->count(),
            'authorizationCodes' => DB::table('oauth_authorization_codes')->where('client_id', $clientId)->count(),
        ];
    }

    /**
     * @return list<\stdClass>
     *
     * @phpstan-impure
     */
    protected function clientAudit(?string $eventType = null): array
    {
        return array_values(array_filter(
            $this->allAudit(),
            fn (\stdClass $row): bool => str_starts_with((string) $row->event_type, 'oauth2Client') && ($eventType === null || $row->event_type === $eventType),
        ));
    }
}
