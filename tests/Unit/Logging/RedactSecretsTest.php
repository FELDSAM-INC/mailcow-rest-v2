<?php

namespace Tests\Unit\Logging;

use App\Logging\RedactSecrets;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * T028 (research R15, FR-055): the log processor drops the context keys that can carry the secrets
 * of feature 012 (identity provider secrets, codes, states, PKCE verifiers, provider tokens).
 */
class RedactSecretsTest extends TestCase
{
    public function test_database_failure_messages_and_previous_exceptions_do_not_expose_bound_values(): void
    {
        $secret = 'synthetic-review-password';
        $query = new QueryException('mysql', 'insert into imapsync (password1) values (?)', [$secret], new RuntimeException('Rejected value '.$secret));

        foreach ([$query, new RuntimeException($query->getMessage(), 0, $query)] as $exception) {
            $record = new LogRecord(new DateTimeImmutable, 'test', Level::Error, $exception->getMessage(), ['exception' => $exception, 'requestId' => 'safe-id']);
            $safe = (new RedactSecrets)($record);
            $encoded = (new JsonFormatter)->format($safe);
            $this->assertStringNotContainsString($secret, $encoded);
            $this->assertStringNotContainsString('insert into', $encoded);
            $this->assertSame('Database query failed.', $safe->message);
            $this->assertSame('safe-id', $safe->context['requestId']);
            $this->assertSame($exception::class, $safe->context['exception']['class']);
        }
    }

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
