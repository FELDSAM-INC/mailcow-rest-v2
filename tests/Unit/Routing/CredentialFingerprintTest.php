<?php

namespace Tests\Unit\Routing;

use App\Support\Http\ETag;
use App\Support\Routing\CredentialFingerprint;
use Tests\TestCase;

/**
 * Feature 009 T011 (FR-008, FR-012, research R2): passwords enter version tags only as an HMAC
 * keyed with the application key, so that a tag never reveals a password or its plain hash.
 */
class CredentialFingerprintTest extends TestCase
{
    public function test_the_fingerprint_is_an_hmac_keyed_with_the_application_key(): void
    {
        $this->assertSame(hash_hmac('sha256', 's3cret', (string) config('app.key')), CredentialFingerprint::of('s3cret'));
        $this->assertNotSame(hash('sha256', 's3cret'), CredentialFingerprint::of('s3cret'));
    }

    public function test_it_changes_with_the_password_and_with_the_key(): void
    {
        $before = CredentialFingerprint::of('s3cret');

        $this->assertNotSame($before, CredentialFingerprint::of('s3cret!'));

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->assertNotSame($before, CredentialFingerprint::of('s3cret'));
    }

    public function test_an_empty_password_has_its_own_fingerprint(): void
    {
        $this->assertSame(hash_hmac('sha256', '', (string) config('app.key')), CredentialFingerprint::of(''));
        $this->assertNotSame(CredentialFingerprint::of(''), CredentialFingerprint::of(' '));
    }

    public function test_a_version_tag_over_a_fingerprint_reveals_neither_the_password_nor_its_hash(): void
    {
        $tag = ETag::of([1, '[smtp.example.net]:587', 'acct', CredentialFingerprint::of('s3cret'), 1]);

        $this->assertStringNotContainsString('s3cret', $tag);
        $this->assertStringNotContainsString(hash('sha256', 's3cret'), $tag);
        $this->assertStringNotContainsString(CredentialFingerprint::of('s3cret'), $tag);
        $this->assertStringNotContainsString(base64_encode('s3cret'), $tag);
    }
}
