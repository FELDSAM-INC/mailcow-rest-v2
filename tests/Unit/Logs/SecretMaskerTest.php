<?php

namespace Tests\Unit\Logs;

use App\Services\Logs\SecretMasker;
use PHPUnit\Framework\TestCase;

/**
 * Feature 011 T044 (FR-028, research R15): secrets in legacy logs are masked by member name.
 */
class SecretMaskerTest extends TestCase
{
    public function test_secret_names_are_masked_at_any_depth(): void
    {
        $masked = (new SecretMasker)->mask([
            'username' => 'alice@example.org',
            'password' => 'Secret1!',
            'password2' => 'Secret1!',
            'PASSWORD_CONFIRM' => 'x',
            'client_secret' => 'abc',
            'token' => 'pushover',
            'private_key_file' => '-----BEGIN PRIVATE KEY-----',
            'key' => 'k',
            'KEY' => 'k2',
            'attr' => ['bind_password' => 'ldap', 'nested' => ['app_token' => 't', 'name' => 'n']],
            'dkim' => ['private' => ['pem' => 'x', 'bits' => 2048]],
        ]);

        $this->assertSame([
            'username' => 'alice@example.org',
            'password' => '*',
            'password2' => '*',
            'PASSWORD_CONFIRM' => '*',
            'client_secret' => '*',
            'token' => '*',
            'private_key_file' => '*',
            'key' => '*',
            'KEY' => '*',
            'attr' => ['bind_password' => '*', 'nested' => ['app_token' => '*', 'name' => 'n']],
            'dkim' => ['private' => '*'],
        ], $masked);
    }

    public function test_similar_names_and_list_elements_are_not_masked_by_name(): void
    {
        $masked = (new SecretMasker)->mask([
            'api_key' => 'visible',
            'keyboard' => 'visible',
            'items' => ['password', 'token', ['password' => 'x']],
        ]);

        $this->assertSame([
            'api_key' => 'visible',
            'keyboard' => 'visible',
            'items' => ['password', 'token', ['password' => '*']],
        ], $masked);
    }

    public function test_scalars_pass_through(): void
    {
        $this->assertSame('text', (new SecretMasker)->mask('text'));
        $this->assertNull((new SecretMasker)->mask(null));
    }
}
