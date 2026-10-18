<?php

namespace App\Services\IdentityProvider;

use App\Audit\AuditEventType;
use App\Auth\IdentityProviderType;
use App\Auth\Principal;
use App\Clients\Keycloak\KeycloakAdminClient;
use App\Clients\Ldap\LdapConnectionSettings;
use App\Clients\Ldap\LdapDirectory;
use App\Clients\Ldap\LdapStatus;
use App\Clients\Oidc\OidcClient;
use App\Clients\Oidc\OidcEndpoints;
use App\Clients\Oidc\OidcFailure;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Connection tests of candidate settings (FR-019, research R11). Nothing is stored; every test is
 * audited with the provider type, the tested address and the outcome.
 *
 *  - Keycloak: a client credentials grant at the realm's token endpoint (legacy's test).
 *  - Generic OIDC: an authorization code exchange with a random, invalid code; the provider must
 *    answer without rejecting the client (`invalid_grant` and similar errors mean the client
 *    authenticated). **Deviation**: legacy expects a success answer to an `OPTIONS` request.
 *  - LDAP: connect with the SSL, StartTLS and certificate settings and bind with the bind DN.
 */
class ConnectionTester
{
    /** OAuth errors of an invalid-code exchange that prove the client authenticated. */
    private const CLIENT_ACCEPTED_ERRORS = ['invalid_grant', 'unauthorized_client', 'unsupported_grant_type', 'invalid_scope'];

    /** cURL error numbers of TLS failures. */
    private const TLS_ERRNOS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public function __construct(
        private readonly KeycloakAdminClient $keycloak,
        private readonly OidcClient $oidc,
        private readonly LdapDirectory $ldap,
        private readonly AuditLogger $audit,
    ) {}

    public function test(IdentityProviderConfig $candidate, #[\SensitiveParameter] string $secret, Principal $actor): ConnectionTestResult
    {
        $result = match ($candidate->type) {
            IdentityProviderType::Keycloak => $this->keycloak($candidate, $secret),
            IdentityProviderType::GenericOidc => $this->genericOidc($candidate, $secret),
            IdentityProviderType::Ldap => $this->ldap($candidate, $secret),
        };

        DB::transaction(fn () => $this->audit->record(
            AuditEventType::IdentityProviderConnectionTest,
            $result->succeeded(),
            $actor,
            IdentityProviderConfigService::target($candidate->type),
            null,
            $result->succeeded() ? null : 'connectionTestFailed',
            [
                'type' => $candidate->type->value,
                'testedAddress' => $result->testedAddress,
                'outcome' => $result->succeeded() ? 'success' : 'failure',
                'failureCategory' => $result->failureCategory,
                'providerStatus' => $result->providerStatus,
            ],
        ));

        return $result;
    }

    private function keycloak(IdentityProviderConfig $candidate, string $secret): ConnectionTestResult
    {
        $address = OidcEndpoints::forConfig($candidate, $secret)->token;

        try {
            $this->keycloak->serviceToken($candidate, $secret);

            return $this->result($candidate, $address, null, 200);
        } catch (OidcFailure $e) {
            return $this->result($candidate, $address, ...self::classify($e, false));
        }
    }

    private function genericOidc(IdentityProviderConfig $candidate, string $secret): ConnectionTestResult
    {
        $endpoints = OidcEndpoints::forConfig($candidate, $secret);

        try {
            $this->oidc->exchangeCode($endpoints, 'restv2-connection-test-'.bin2hex(random_bytes(16)), '', (string) $candidate->get('legacyReturnUrl'));

            return $this->result($candidate, $endpoints->token, null, 200);
        } catch (OidcFailure $e) {
            return $this->result($candidate, $endpoints->token, ...self::classify($e, true));
        }
    }

    private function ldap(IdentityProviderConfig $candidate, string $secret): ConnectionTestResult
    {
        $settings = LdapConnectionSettings::fromConfig($candidate, $secret);
        $result = $this->ldap->testBind($settings);

        [$category, $status] = match ($result->status) {
            LdapStatus::Ok => [null, null],
            LdapStatus::CredentialsRejected, LdapStatus::WrongPassword => [ConnectionTestResult::CREDENTIALS_REJECTED, 49],
            LdapStatus::TlsFailure => [ConnectionTestResult::TLS_CERTIFICATE_REJECTED, null],
            LdapStatus::TimedOut => [ConnectionTestResult::TIMED_OUT, null],
            LdapStatus::Unreachable => [ConnectionTestResult::UNREACHABLE, null],
            LdapStatus::Unexpected, LdapStatus::UnknownUser => [ConnectionTestResult::UNEXPECTED_RESPONSE, $result->code],
        };

        return $this->result($candidate, $settings->uris(), $category, $status);
    }

    /**
     * The failure category and provider status of a failed HTTP call.
     *
     * @return array{0: string|null, 1: int|null}
     */
    private static function classify(OidcFailure $e, bool $invalidCodeExchange): array
    {
        if ($e->curlErrno !== null) {
            return [match (true) {
                $e->curlErrno === 28 => ConnectionTestResult::TIMED_OUT,
                in_array($e->curlErrno, self::TLS_ERRNOS, true) => ConnectionTestResult::TLS_CERTIFICATE_REJECTED,
                default => ConnectionTestResult::UNREACHABLE,
            }, null];
        }

        if ($e->status === null) {
            return [ConnectionTestResult::UNREACHABLE, null];
        }

        return match (true) {
            $e->error === 'invalid_client' || $e->status === 401 => [ConnectionTestResult::CREDENTIALS_REJECTED, $e->status],
            $invalidCodeExchange && in_array($e->error, self::CLIENT_ACCEPTED_ERRORS, true) => [null, $e->status],
            default => [ConnectionTestResult::UNEXPECTED_RESPONSE, $e->status],
        };
    }

    private function result(IdentityProviderConfig $candidate, string $address, ?string $category, ?int $status): ConnectionTestResult
    {
        return new ConnectionTestResult($candidate->type, $address, CarbonImmutable::now('UTC'), $category, $status);
    }
}
