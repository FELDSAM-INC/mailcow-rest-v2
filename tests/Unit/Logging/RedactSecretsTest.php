<?php

namespace Tests\Unit\Logging;

use App\Logging\RedactSecrets;
use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * T028 (research R15, FR-055): the log processor drops the context keys that can carry the secrets
 * of feature 012 (identity provider secrets, codes, states, PKCE verifiers, provider tokens).
 */
class RedactSecretsTest extends TestCase
{
    public function test_identity_provider_secrets_are_dropped_at_any_depth(): void
    {
        $record = new LogRecord(new DateTimeImmutable, 'test', Level::Info, 'probe', [
            'clientSecret' => 'kc-secret',
            'bindPassword' => 'ldap-secret',
            'code' => 'auth-code',
            'state' => 'sso-state',
            'codeVerifier' => 'pkce-verifier',
            'refreshToken' => 'refresh',
            'access_token' => 'access',
            'bindpass' => 'ldap-secret',
            'cookie' => 'session',
            'provider' => ['type' => 'keycloak', 'state' => 'nested-state', 'verifier' => 'v'],
            'kept' => 'yes',
        ]);

        $context = (new RedactSecrets)($record)->context;

        $this->assertSame(['provider' => ['type' => 'keycloak'], 'kept' => 'yes'], $context);
    }
}
