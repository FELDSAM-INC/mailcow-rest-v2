<?php

namespace Tests\Unit\Clients\SyncSources;

use App\Clients\SyncSources\OAuthFailure;
use App\Clients\SyncSources\OAuthHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OAuthHttpClientTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function nonPublic(): array
    {
        return array_map(fn ($ip) => [$ip], array_combine(
            ['loopback', 'private', 'metadata', 'cgnat', 'benchmark', 'multicast', 'ipv6local', 'ipv6mapped', 'ipv6transition', 'ipv6doc'],
            ['127.0.0.1', '10.2.3.4', '169.254.169.254', '100.64.1.1', '198.18.0.1', '224.0.0.1', '::1', '::ffff:127.0.0.1', '2002:7f00::1', '2001:db8::1']));
    }

    #[DataProvider('nonPublic')]
    public function test_rejects_internal_and_special_addresses(string $ip): void
    {
        self::assertFalse(OAuthHttpClient::isPublic($ip));
    }

    public function test_pins_dns_and_never_follows_redirects_or_uses_a_proxy(): void
    {
        $client = new class extends OAuthHttpClient
        {
            /** @var array<int, mixed> */
            public array $options = [];

            protected function resolve(string $host): array
            {
                return ['8.8.8.8', '2606:4700:4700::1111'];
            }

            protected function send(array $options): array
            {
                $this->options = $options;

                return ['{"access_token":"test"}', 200];
            }
        };
        $client->request('https://provider.example/token', true, ['client_secret' => 'test']);
        self::assertSame(['provider.example:443:8.8.8.8,[2606:4700:4700::1111]'], $client->options[CURLOPT_RESOLVE]);
        self::assertFalse($client->options[CURLOPT_FOLLOWLOCATION]);
        self::assertSame('', $client->options[CURLOPT_PROXY]);
        self::assertTrue($client->options[CURLOPT_SSL_VERIFYPEER]);
    }

    public function test_mixed_public_private_resolution_is_refused_before_sending_credentials(): void
    {
        $client = new class extends OAuthHttpClient
        {
            protected function resolve(string $host): array
            {
                return ['8.8.8.8', '127.0.0.1'];
            }

            protected function send(array $options): array
            {
                throw new \LogicException('Must not send');
            }
        };
        $this->expectException(OAuthFailure::class);
        $client->request('https://provider.example/token', true, ['client_secret' => 'test']);
    }

    public function test_provider_errors_cannot_reflect_secrets_in_diagnostics(): void
    {
        $client = new class extends OAuthHttpClient
        {
            protected function send(array $options): array
            {
                return ['{"error_description":"client_secret=reflected-secret"}', 400];
            }
        };
        $this->expectExceptionMessage('providerRefused');
        $client->request('https://provider.example/token', false);
    }

    public function test_https_and_no_userinfo_are_required_even_for_admin_sources(): void
    {
        foreach (['http://example.org/token', 'https://user:password@example.org/token', 'https://example.org/token#fragment', "https://example.org/\r\n"] as $url) {
            try {
                (new OAuthHttpClient)->pin($url, false);
                self::fail('Unsafe endpoint accepted');
            } catch (OAuthFailure $e) {
                self::assertSame('endpointNotAllowed', $e->getMessage());
            }
        }
    }
}
