<?php

namespace Tests\Feature\Settings;

use Illuminate\Support\Facades\Redis;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T030 (FR-029 to FR-034, AS-1.7, AS-1.8): the API-owned brand colours, stored in the
 * Redis hash `restv2:brand-colors`, contrast-checked against the light and dark reference
 * surfaces, and published with their foregrounds in the public configuration.
 */
class BrandColorsTest extends TestCase
{
    use SettingsTestHelpers;

    /**
     * @return array<string, string>
     */
    private function storedColors(): array
    {
        $values = $this->redis()->hGetAll('restv2:brand-colors');

        return is_array($values) ? $values : [];
    }

    public function test_a_fresh_server_has_no_brand_colours(): void
    {
        $this->asAdmin();

        $response = $this->getJson('/api/v2/settings/brand-colors')->assertOk();

        $this->assertSame(['light' => null, 'dark' => null, 'effective' => ['light' => null, 'dark' => null]], array_diff_key((array) $response->json('data'), ['etag' => true]));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_a_colour_below_the_minimum_contrast_is_refused_with_its_ratio(): void
    {
        $this->asAdmin();

        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => '#FFD700'])->assertStatus(422)
            ->assertJsonPath('errors.light.0.code', 'insufficientContrast')
            ->assertJsonPath('errors.light.0.params', ['ratio' => 1.4, 'minimum' => 4.5, 'surface' => '#ffffff']);
        $this->send('PATCH', '/api/v2/settings/brand-colors', ['dark' => '#1d4ed8'])->assertStatus(422)
            ->assertJsonPath('errors.dark.0.params', ['ratio' => 2.19, 'minimum' => 4.5, 'surface' => '#1d2939']);

        $this->assertSame([], $this->storedColors());
    }

    public function test_a_light_colour_is_stored_in_lower_case_and_applies_to_the_light_theme_only_when_too_dark(): void
    {
        $this->asAdmin();

        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => '#1D4ED8'])->assertOk()
            ->assertJsonPath('data.light', '#1d4ed8')
            ->assertJsonPath('data.effective.light', ['color' => '#1d4ed8', 'foreground' => '#ffffff'])
            ->assertJsonPath('data.effective.dark', null);

        $this->assertSame(['light' => '#1d4ed8'], $this->storedColors());
        $this->assertSame('#1d4ed8', Redis::connection('restv2')->hget('brand-colors', 'light'));
    }

    public function test_a_dark_colour_applies_to_the_dark_theme_and_null_clears(): void
    {
        $this->asAdmin();

        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => '#1d4ed8', 'dark' => '#93c5fd'])->assertOk()
            ->assertJsonPath('data.effective.dark', ['color' => '#93c5fd', 'foreground' => '#000000']);
        $this->send('PATCH', '/api/v2/settings/brand-colors', ['dark' => null])->assertOk()
            ->assertJsonPath('data.dark', null)
            ->assertJsonPath('data.light', '#1d4ed8');

        $this->assertSame(['light' => '#1d4ed8'], $this->storedColors());
    }

    public function test_the_public_configuration_follows_on_its_next_read_and_its_tag_changes(): void
    {
        $this->asAdmin();
        $before = $this->getJson('/api/v2/public-config')->assertOk();
        $before->assertJsonPath('data.brandColors', ['light' => null, 'dark' => null]);

        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => '#1d4ed8', 'dark' => '#93c5fd'])->assertOk();

        $this->forgetCookies();
        $after = $this->getJson('/api/v2/public-config')->assertOk();
        $after->assertJsonPath('data.brandColors.light', ['color' => '#1d4ed8', 'foreground' => '#ffffff'])
            ->assertJsonPath('data.brandColors.dark', ['color' => '#93c5fd', 'foreground' => '#000000']);
        $this->assertNotSame($before->headers->get('ETag'), $after->headers->get('ETag'));
    }

    public function test_invalid_values_and_an_outdated_tag_change_nothing(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();
        $etag = (string) $this->getJson('/api/v2/settings/brand-colors')->json('data.etag');
        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => '#1d4ed8'])->assertOk();

        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => '#fff'])->assertStatus(422)->assertJsonPath('errors.light.0.params.format', 'hexColor');
        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => 'blue'])->assertStatus(422);
        $this->send('PATCH', '/api/v2/settings/brand-colors', [])->assertStatus(422);
        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => null], ['If-Match' => $etag])->assertStatus(412);

        $this->assertSame(['light' => '#1d4ed8'], $this->storedColors());
    }

    public function test_demo_mode_refuses_changes_and_changes_are_audited(): void
    {
        $this->asAdmin();
        config(['restv2.demo_mode' => true]);
        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => '#1d4ed8'])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/demo-mode');
        $this->assertSame([], $this->storedColors());

        config(['restv2.demo_mode' => false]);
        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => '#1d4ed8'])->assertOk();
        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => '#1d4ed8'])->assertOk();

        $records = $this->successesOf('brandColorsUpdate');
        $this->assertCount(1, $records, 'A change that changes nothing is not audited.');
        $this->assertSame(['light' => ['old' => null, 'new' => '#1d4ed8']], $records[0]->details['changes']);
        $this->assertSame('brandColors', $records[0]->target_type);
    }
}
