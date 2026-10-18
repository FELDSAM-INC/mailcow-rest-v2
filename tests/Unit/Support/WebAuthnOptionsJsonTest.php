<?php

namespace Tests\Unit\Support;

use App\Support\WebAuthn\OptionsJson;
use lbuchs\WebAuthn\WebAuthn;
use Tests\Support\Contract\OpenApiContract;
use Tests\TestCase;

/**
 * T036 (R9): lbuchs's argument objects become the contract's WebAuthn Level 3 JSON.
 */
class WebAuthnOptionsJsonTest extends TestCase
{
    private const BASE64URL = '/^[A-Za-z0-9_-]+$/';

    private function server(): WebAuthn
    {
        return new WebAuthn('mail.example.org - mail UI', 'mail.example.org', ['apple', 'android-key', 'android-safetynet', 'fido-u2f', 'none', 'packed', 'tpm'], true);
    }

    public function test_passkey_challenge_options(): void
    {
        $options = OptionsJson::request($this->server()->getGetArgs([], 300, false, false, false, false, false, 'preferred'));

        $this->assertSame([], $options['allowCredentials']);
        $this->assertSame(300000, $options['timeout']);
        $this->assertSame('mail.example.org', $options['rpId']);
        $this->assertSame('preferred', $options['userVerification']);
        $this->assertMatchesRegularExpression(self::BASE64URL, $options['challenge']);
        $this->assertArrayNotHasKey('extensions', $options);
        $this->assertSame([], OpenApiContract::shared()->validateComponent($options, 'WebAuthnRequestOptions'));
    }

    public function test_second_factor_challenge_options_keep_appid(): void
    {
        $ids = [random_bytes(32), random_bytes(64)];
        $options = OptionsJson::request($this->server()->getGetArgs($ids, 300, true, true, true, true, true, false), 'https://mail.example.org');

        $this->assertCount(2, $options['allowCredentials']);
        foreach ($options['allowCredentials'] as $i => $descriptor) {
            $this->assertSame('public-key', $descriptor['type']);
            $this->assertMatchesRegularExpression(self::BASE64URL, $descriptor['id']);
            $this->assertSame($ids[$i], base64_decode(strtr($descriptor['id'], '-_', '+/'), true));
        }
        $this->assertSame(['appid' => 'https://mail.example.org'], $options['extensions']);
        $this->assertSame([], OpenApiContract::shared()->validateComponent($options, 'WebAuthnRequestOptions'));
    }

    public function test_registration_options(): void
    {
        $exclude = [random_bytes(32)];
        $options = OptionsJson::creation($this->server()->getCreateArgs('alice@example.org', 'alice@example.org', 'alice@example.org', 300, true, 'preferred', null, $exclude));

        $this->assertSame('required', $options['authenticatorSelection']['residentKey']);
        $this->assertTrue($options['authenticatorSelection']['requireResidentKey']);
        $this->assertSame('preferred', $options['authenticatorSelection']['userVerification']);
        $this->assertSame(300000, $options['timeout']);
        $this->assertEquals(['id' => 'mail.example.org', 'name' => 'mail.example.org - mail UI'], $options['rp']);
        $this->assertSame('alice@example.org', base64_decode(strtr($options['user']['id'], '-_', '+/'), true));
        $this->assertMatchesRegularExpression(self::BASE64URL, $options['user']['id']);
        $this->assertMatchesRegularExpression(self::BASE64URL, $options['challenge']);
        $this->assertCount(1, $options['excludeCredentials']);
        $this->assertMatchesRegularExpression(self::BASE64URL, $options['excludeCredentials'][0]['id']);
        $this->assertArrayNotHasKey('exts', $options['extensions'] ?? []);
        $this->assertSame([], OpenApiContract::shared()->validateComponent($options, 'WebAuthnCreationOptions'));
    }

    /**
     * Orchestrator decision: the attestation conveyance is lbuchs's, as legacy sends it. `indirect`
     * by default (no browser privacy prompt), `direct` only with root certificates
     * (WEBAUTHN_ONLY_TRUSTED_VENDORS=y), `none` when only the `none` format is accepted.
     */
    public function test_registration_options_keep_the_attestation_conveyance_of_lbuchs(): void
    {
        $create = fn (WebAuthn $server): array => OptionsJson::creation($server->getCreateArgs('a', 'a', 'a', 300, true, 'preferred'));

        $this->assertSame('indirect', $create($this->server())['attestation']);
        $this->assertSame('none', $create(new WebAuthn('x', 'mail.example.org', ['none'], true))['attestation']);

        $trusted = new WebAuthn('x', 'mail.example.org', null, true);
        $trusted->addRootCertificates(resource_path('webauthn/rootCertificates'));
        $this->assertSame('direct', $create($trusted)['attestation']);
    }

    public function test_the_conversion_does_not_depend_on_lbuchs_encoding_mode(): void
    {
        $server = new WebAuthn('x', 'mail.example.org', null, false);
        $options = OptionsJson::request($server->getGetArgs([random_bytes(16)], 300));

        $this->assertMatchesRegularExpression(self::BASE64URL, $options['challenge']);
        $this->assertMatchesRegularExpression(self::BASE64URL, $options['allowCredentials'][0]['id']);
    }
}
