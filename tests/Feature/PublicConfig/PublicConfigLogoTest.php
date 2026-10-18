<?php

namespace Tests\Feature\PublicConfig;

use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * T092 (FR-042, R15): the logos uploaded in the legacy UI, served with their media type, version
 * caching and a sandboxing Content-Security-Policy.
 */
class PublicConfigLogoTest extends TestCase
{
    private const CSP = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    /**
     * stored media type => [served media type, bytes]
     *
     * @return array<string, array{string, string, string}>
     */
    public static function images(): array
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        return [
            'png' => ['image/png', 'image/png', $png],
            'x-png' => ['image/x-png', 'image/png', $png],
            'jpeg' => ['image/jpeg', 'image/jpeg', "\xFF\xD8\xFF\xE0jpeg"],
            'pjpeg' => ['image/pjpeg', 'image/jpeg', "\xFF\xD8\xFF\xE0jpeg"],
            'gif' => ['image/gif', 'image/gif', 'GIF89a'],
            'svg' => ['image/svg+xml', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
        ];
    }

    private function store(string $key, string $mime, string $bytes): string
    {
        $value = 'data:'.$mime.';base64,'.base64_encode($bytes);
        Redis::connection('mailcow')->set($key, $value);

        return $value;
    }

    #[DataProvider('images')]
    public function test_an_uploaded_logo_is_served_with_its_media_type(string $stored, string $served, string $bytes): void
    {
        $this->store('MAIN_LOGO', $stored, $bytes);

        $response = $this->get('/api/v2/public-config/logos/light')->assertOk();

        $this->assertSame($served, $response->headers->get('Content-Type'));
        $this->assertSame($bytes, $response->getContent());
        $response->assertHeader('Content-Security-Policy', self::CSP);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_the_version_from_the_public_configuration_makes_it_cacheable(): void
    {
        $value = $this->store('MAIN_LOGO_DARK', 'image/gif', 'GIF89a');
        $url = (string) $this->getJson('/api/v2/public-config')->json('data.logos.dark.url');
        $this->assertSame('/api/v2/public-config/logos/dark?v='.substr(hash('sha256', $value), 0, 16), $url);

        $cached = $this->get($url)->assertOk();
        $this->assertSame('immutable, max-age=31536000, public', $cached->headers->get('Cache-Control'));

        $this->get('/api/v2/public-config/logos/dark')->assertOk()->assertHeader('Cache-Control', 'no-cache, public');
        $this->get('/api/v2/public-config/logos/dark?v=0123456789abcdef')->assertOk()->assertHeader('Cache-Control', 'no-cache, public');
    }

    public function test_a_conditional_request_is_answered_not_modified(): void
    {
        $this->store('MAIN_LOGO', 'image/gif', 'GIF89a');
        $etag = (string) $this->get('/api/v2/public-config/logos/light')->headers->get('ETag');

        $response = $this->get('/api/v2/public-config/logos/light', ['If-None-Match' => $etag])->assertStatus(304);

        $response->assertHeader('ETag', $etag);
        $this->assertSame('', $response->getContent());
    }

    public function test_an_unset_or_unusable_logo_is_not_found(): void
    {
        $this->getJson('/api/v2/public-config/logos/light')->assertNotFound()->assertJsonPath('type', 'about:blank');

        $this->store('MAIN_LOGO', 'text/html', '<script>alert(1)</script>');
        $this->getJson('/api/v2/public-config/logos/light')->assertNotFound();

        Redis::connection('mailcow')->set('MAIN_LOGO_DARK', 'not a data uri');
        $this->getJson('/api/v2/public-config/logos/dark')->assertNotFound();
    }

    public function test_an_invalid_variant_is_not_a_route(): void
    {
        $this->withoutRequestValidation();

        $this->getJson('/api/v2/public-config/logos/sepia')->assertNotFound();
    }
}
