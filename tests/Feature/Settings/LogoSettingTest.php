<?php

namespace Tests\Feature\Settings;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Redis\UnavailableSharedStore;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T026 (FR-012 to FR-018, AS-1.1 to AS-1.4, SC-003): administrators upload, read and
 * delete the light and dark logos, stored as legacy stores them (`MAIN_LOGO`, `MAIN_LOGO_DARK`,
 * `data:<type>;base64,<data>`) with the type detected from the content; hostile files are refused
 * and nothing changes; signed-out clients see the result in the public configuration.
 */
class LogoSettingTest extends TestCase
{
    use SettingsTestHelpers;

    private const DIR = __DIR__.'/../../fixtures/logos/';

    private static function fixture(string $path): string
    {
        return (string) file_get_contents(self::DIR.$path);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function upload(string $variant, string $bytes, string $contentType = 'application/octet-stream', array $headers = []): TestResponse
    {
        return $this->sendRaw('PUT', "/api/v2/settings/logos/{$variant}", $bytes, $contentType, $headers);
    }

    /**
     * @return array<string, array{string, string, string, int|null, int|null}>
     */
    public static function validLogos(): array
    {
        return [
            'png' => ['valid/logo-300x80.png', 'image/png', 'image/png', 300, 80],
            'jpeg' => ['valid/logo-200x60.jpg', 'image/jpeg', 'image/jpeg', 200, 60],
            'animated gif' => ['valid/animated-120x40.gif', 'image/gif', 'image/gif', 120, 40],
            'svg' => ['valid/logo-300x80.svg', 'image/svg+xml', 'image/svg+xml', 300, 80],
            'svg without dimensions' => ['valid/logo-no-dimensions.svg', 'image/svg+xml', 'image/svg+xml', null, null],
            'inkscape svg' => ['valid/inkscape-metadata-2in.svg', 'application/octet-stream', 'image/svg+xml', 192, null],
            'svg with embedded png' => ['valid/embedded-png-and-references.svg', 'image/svg+xml', 'image/svg+xml', 300, 80],
            'png declared as jpeg' => ['valid/logo-300x80.png', 'image/jpeg', 'image/png', 300, 80],
            'png at the limit' => ['valid/logo-1920x1920.png', 'image/png', 'image/png', 1920, 1920],
        ];
    }

    #[DataProvider('validLogos')]
    public function test_an_administrator_uploads_a_logo_stored_with_its_detected_type(string $file, string $declared, string $type, ?int $width, ?int $height): void
    {
        $this->asAdmin();
        $bytes = self::fixture($file);

        $response = $this->upload('light', $bytes, $declared)->assertOk();

        $response->assertJsonPath('data.variant', 'light')
            ->assertJsonPath('data.isSet', true)
            ->assertJsonPath('data.mediaType', $type)
            ->assertJsonPath('data.sizeBytes', strlen($bytes))
            ->assertJsonPath('data.width', $width)
            ->assertJsonPath('data.height', $height);
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
        $this->assertSame('data:'.$type.';base64,'.base64_encode($bytes), $this->sharedGet('MAIN_LOGO'));
        $this->assertNull($this->sharedGet('MAIN_LOGO_DARK'));
    }

    public function test_an_administrator_key_uploads_and_signed_out_clients_receive_the_logo(): void
    {
        $bytes = self::fixture('valid/logo-300x80.png');

        $this->upload('light', $bytes, 'image/png', $this->adminKey())->assertOk();
        $url = (string) $this->getJson('/api/v2/settings/logos/light', $this->adminKey('readOnly'))->assertOk()->json('data.url');

        $this->forgetCookies();
        $public = $this->getJson('/api/v2/public-config')->assertOk();
        $this->assertSame($url, $public->json('data.logos.light.url'));
        $this->assertFalse($public->json('data.logos.light.isDefault'));
        $this->assertTrue($public->json('data.logos.dark.isDefault'));
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_the_dark_logo_is_independent(): void
    {
        $this->asAdmin();
        $this->upload('light', self::fixture('valid/logo-300x80.png'), 'image/png')->assertOk();

        $this->upload('dark', self::fixture('valid/logo-300x80.svg'), 'image/svg+xml')->assertOk()->assertJsonPath('data.variant', 'dark');

        $this->assertStringStartsWith('data:image/png;base64,', (string) $this->sharedGet('MAIN_LOGO'));
        $this->assertStringStartsWith('data:image/svg+xml;base64,', (string) $this->sharedGet('MAIN_LOGO_DARK'));
        $this->getJson('/api/v2/settings/logos/dark')->assertOk()->assertJsonPath('data.mediaType', 'image/svg+xml');
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function hostileLogos(): array
    {
        $cases = [];

        foreach (glob(self::DIR.'hostile/*.svg') ?: [] as $path) {
            $construct = explode('--', basename($path))[0];
            $cases[basename($path)] = ['hostile/'.basename($path), 'unsafeSvgContent', ['construct' => $construct]];
        }

        $cases['pdf named logo.png'] = ['hostile/unsupportedImageType--pdf-named-logo.png', 'unsupportedImageType', ['allowed' => ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml']]];
        $cases['1921 pixels wide'] = ['hostile/imageDimensionsExceeded--1921x10.png', 'imageDimensionsExceeded', ['maxWidth' => 1920, 'maxHeight' => 1920, 'width' => 1921, 'height' => 10]];
        $cases['truncated png'] = ['hostile/invalidImage--truncated.png', 'invalidImage', []];

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    #[DataProvider('hostileLogos')]
    public function test_a_hostile_file_is_refused_on_body_and_nothing_changes(string $file, string $rule, array $params): void
    {
        $this->asAdmin();
        $this->sharedValue('MAIN_LOGO', 'data:image/png;base64,'.base64_encode(self::fixture('valid/logo-300x80.png')));
        $before = $this->sharedGet('MAIN_LOGO');

        $response = $this->upload('light', self::fixture($file), 'image/png')->assertStatus(422);

        $response->assertJsonPath('errors.body.0.code', $rule);

        foreach ($params as $name => $value) {
            $response->assertJsonPath("errors.body.0.params.{$name}", $value);
        }

        $this->assertSame($before, $this->sharedGet('MAIN_LOGO'));
        $this->assertSame([], $this->successesOf('logoReplacement'));
        $this->assertCount(1, $this->refusalsOf('logoReplacement'));
    }

    public function test_a_file_over_the_size_limit_is_too_long(): void
    {
        $this->asAdmin();

        $this->upload('light', str_repeat("\x89PNG\r\n\x1a\n", 2 * 1048576 + 1))
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0.code', 'tooLong')
            ->assertJsonPath('errors.body.0.params.max', 15728640)
            ->assertJsonPath('errors.body.0.params.unit', 'bytes');
        $this->assertNull($this->sharedGet('MAIN_LOGO'));
    }

    public function test_the_operator_s_logo_limits_apply(): void
    {
        $this->asAdmin();
        config(['restv2.vars_overrides.LOGO_LIMITS' => '{"max_size": 15728640, "max_width": 200, "max_height": 1920}']);

        $this->upload('light', self::fixture('valid/logo-300x80.png'), 'image/png')
            ->assertStatus(422)
            ->assertJsonPath('errors.body.0.code', 'imageDimensionsExceeded')
            ->assertJsonPath('errors.body.0.params.maxWidth', 200);
    }

    public function test_deleting_a_logo_removes_only_its_key_and_the_default_logo_returns(): void
    {
        $this->asAdmin();
        $this->upload('light', self::fixture('valid/logo-300x80.png'), 'image/png')->assertOk();
        $this->upload('dark', self::fixture('valid/logo-300x80.svg'), 'image/svg+xml')->assertOk();

        $this->send('DELETE', '/api/v2/settings/logos/light')->assertNoContent();

        $this->assertNull($this->sharedGet('MAIN_LOGO'));
        $this->assertNotNull($this->sharedGet('MAIN_LOGO_DARK'));
        $this->getJson('/api/v2/settings/logos/light')->assertOk()->assertJsonPath('data.isSet', false)->assertJsonPath('data.mediaType', null)->assertJsonPath('data.url', null);
        $this->forgetCookies();
        $this->getJson('/api/v2/public-config')->assertOk()->assertJsonPath('data.logos.light.isDefault', true)->assertJsonPath('data.logos.light.url', '/img/cow_mailcow.svg');
    }

    public function test_deleting_an_unset_logo_succeeds_without_an_audit_record(): void
    {
        $this->asAdmin();

        $this->send('DELETE', '/api/v2/settings/logos/dark')->assertNoContent();

        $this->assertSame([], $this->auditOf('logoDeletion'));
    }

    public function test_an_outdated_tag_is_refused_and_nothing_changes(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/settings/logos/light')->assertOk()->json('data.etag');
        $this->sharedValue('MAIN_LOGO', 'data:image/png;base64,'.base64_encode(self::fixture('valid/logo-300x80.png')));

        $this->upload('light', self::fixture('valid/logo-200x60.jpg'), 'image/jpeg', ['If-Match' => $etag])->assertStatus(412);
        $this->send('DELETE', '/api/v2/settings/logos/light', null, ['If-Match' => $etag])->assertStatus(412);

        $this->assertStringStartsWith('data:image/png;base64,', (string) $this->sharedGet('MAIN_LOGO'));
    }

    public function test_the_current_tag_is_accepted(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/settings/logos/light')->assertOk()->json('data.etag');

        $new = $this->upload('light', self::fixture('valid/logo-200x60.jpg'), 'image/jpeg', ['If-Match' => $etag])->assertOk()->json('data.etag');

        $this->assertNotSame($etag, $new);
        $this->send('DELETE', '/api/v2/settings/logos/light', null, ['If-Match' => (string) $new])->assertNoContent();
    }

    public function test_legacy_media_types_read_normalised(): void
    {
        $this->asAdmin();
        $this->sharedValue('MAIN_LOGO', 'data:image/x-png;base64,'.base64_encode(self::fixture('valid/logo-300x80.png')));
        $this->sharedValue('MAIN_LOGO_DARK', 'data:image/pjpeg;base64,'.base64_encode(self::fixture('valid/logo-200x60.jpg')));

        $this->getJson('/api/v2/settings/logos/light')->assertOk()->assertJsonPath('data.mediaType', 'image/png')->assertJsonPath('data.width', 300);
        $this->getJson('/api/v2/settings/logos/dark')->assertOk()->assertJsonPath('data.mediaType', 'image/jpeg')->assertJsonPath('data.height', 60);
    }

    public function test_a_hostile_logo_stored_by_legacy_stays_readable_and_replaceable(): void
    {
        $this->asAdmin();
        $hostile = self::fixture('hostile/eventHandlerAttribute--onload.svg');
        $this->sharedValue('MAIN_LOGO', 'data:image/svg+xml;base64,'.base64_encode($hostile));

        $this->getJson('/api/v2/settings/logos/light')->assertOk()->assertJsonPath('data.isSet', true)->assertJsonPath('data.mediaType', 'image/svg+xml')->assertJsonPath('data.sizeBytes', strlen($hostile));
        $this->upload('light', self::fixture('valid/logo-300x80.png'), 'image/png')->assertOk();
        $this->assertStringStartsWith('data:image/png;base64,', (string) $this->sharedGet('MAIN_LOGO'));
    }

    public function test_an_unknown_stored_media_type_reads_as_set_without_a_type(): void
    {
        $this->asAdmin();
        $this->sharedValue('MAIN_LOGO', 'data:image/bmp;base64,'.base64_encode('BMxxxx'));

        $this->getJson('/api/v2/settings/logos/light')->assertOk()->assertJsonPath('data.isSet', true)->assertJsonPath('data.mediaType', null)->assertJsonPath('data.url', null);
    }

    public function test_demo_mode_refuses_uploads_and_deletions(): void
    {
        $this->asAdmin();
        config(['restv2.demo_mode' => true]);

        $this->upload('light', self::fixture('valid/logo-300x80.png'), 'image/png')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/demo-mode');
        $this->send('DELETE', '/api/v2/settings/logos/light')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/demo-mode');
        $this->getJson('/api/v2/settings/logos/light')->assertOk();

        $this->assertNull($this->sharedGet('MAIN_LOGO'));
        $this->assertSame(['demoMode'], array_column($this->refusalsOf('logoReplacement'), 'failure_reason'));
        $this->assertSame(['demoMode'], array_column($this->refusalsOf('logoDeletion'), 'failure_reason'));
    }

    public function test_an_unavailable_shared_store_is_a_temporary_failure(): void
    {
        $this->asAdmin();
        UnavailableSharedStore::bind($this->app);

        $this->getJson('/api/v2/settings/logos/light')->assertStatus(503)->assertHeader('Retry-After');
        $this->upload('light', self::fixture('valid/logo-300x80.png'), 'image/png')->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure');
        $this->send('DELETE', '/api/v2/settings/logos/light')->assertStatus(503);
    }

    public function test_replacements_and_deletions_are_audited_with_metadata_only(): void
    {
        $this->asAdmin();
        $png = self::fixture('valid/logo-300x80.png');
        $svg = self::fixture('valid/logo-300x80.svg');

        $this->upload('light', $png, 'image/png')->assertOk();
        $this->upload('light', $svg, 'image/svg+xml')->assertOk();
        $this->send('DELETE', '/api/v2/settings/logos/light')->assertNoContent();

        $replacements = $this->successesOf('logoReplacement');
        $this->assertCount(2, $replacements);
        $this->assertSame(['type' => 'logo', 'id' => 'light'], ['type' => $replacements[0]->target_type, 'id' => $replacements[0]->target_id]);
        $this->assertNull($replacements[0]->details['old']);
        $this->assertSame(['mediaType' => 'image/png', 'sizeBytes' => strlen($png), 'width' => 300, 'height' => 80], $replacements[0]->details['new']);
        $this->assertSame(['mediaType' => 'image/png', 'sizeBytes' => strlen($png), 'width' => 300, 'height' => 80], $replacements[1]->details['old']);
        $this->assertSame(['mediaType' => 'image/svg+xml', 'sizeBytes' => strlen($svg), 'width' => 300, 'height' => 80], $replacements[1]->details['new']);
        $deletion = $this->successesOf('logoDeletion');
        $this->assertCount(1, $deletion);
        $this->assertSame('image/svg+xml', $deletion[0]->details['old']['mediaType']);
        $this->assertStringNotContainsString(base64_encode($png), json_encode($this->auditOf('logoReplacement')) ?: '');
    }

    public function test_other_roles_are_forbidden(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson('/api/v2/settings/logos/light')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->upload('light', self::fixture('valid/logo-300x80.png'), 'image/png')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->assertNull($this->sharedGet('MAIN_LOGO'));
    }
}
