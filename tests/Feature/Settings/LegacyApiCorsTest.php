<?php

namespace Tests\Feature\Settings;

use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T081 (FR-068 to FR-071, AS-7.3, AS-7.4): the CORS settings of the legacy `/api/v1` in
 * legacy's `CORS_SETTINGS` hash, origins and methods joined with `, ` as legacy's `implode()`
 * writes them; every origin checked like legacy's valid_origin(); the merged settings are validated
 * as a whole.
 */
class LegacyApiCorsTest extends TestCase
{
    use SettingsTestHelpers;

    private const URI = '/api/v2/settings/legacy-api-cors';

    /**
     * @return array<string, string>
     */
    private function stored(): array
    {
        $values = $this->redis()->hGetAll('CORS_SETTINGS');

        return is_array($values) ? $values : [];
    }

    public function test_a_fresh_server_allows_its_own_origin_with_the_four_methods(): void
    {
        $this->asAdmin();

        $response = $this->getJson(self::URI)->assertOk();

        $this->assertSame(['allowedOrigins' => [], 'allowedMethods' => ['GET', 'POST', 'PUT', 'DELETE'], 'serverOrigin' => 'https://mail.example.org'], array_diff_key((array) $response->json('data'), ['etag' => true]));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_origins_and_methods_are_stored_as_legacy_stores_them(): void
    {
        $this->asAdmin();

        $this->send('PATCH', self::URI, ['allowedOrigins' => ['https://Portal.example.org', '*', 'https://portal.example.org'], 'allowedMethods' => ['GET', 'POST', 'GET']])->assertOk()
            ->assertJsonPath('data.allowedOrigins', ['https://portal.example.org', '*'])
            ->assertJsonPath('data.allowedMethods', ['GET', 'POST']);

        $this->assertSame(['allowed_origins' => 'https://portal.example.org, *', 'allowed_methods' => 'GET, POST'], $this->stored());
    }

    public function test_an_origin_with_a_path_is_refused_by_index(): void
    {
        $this->asAdmin();

        $errors = (array) $this->send('PATCH', self::URI, ['allowedOrigins' => ['https://ok.example.org', 'https://portal.example.org/app/']])->assertStatus(422)->json('errors');
        $this->assertSame(['invalidFormat', ['format' => 'origin']], [$errors['allowedOrigins.1'][0]['code'], $errors['allowedOrigins.1'][0]['params']]);

        $this->assertSame([], $this->stored());
    }

    public function test_invalid_methods_are_refused(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $this->send('PATCH', self::URI, ['allowedMethods' => []])->assertStatus(422)->assertJsonPath('errors.allowedMethods.0.code', 'tooFew');
        $errors = (array) $this->send('PATCH', self::URI, ['allowedMethods' => ['GET', 'PATCH']])->assertStatus(422)->json('errors');
        $this->assertSame('notInList', $errors['allowedMethods.1'][0]['code']);
        $this->send('PATCH', self::URI, ['allowedOrigins' => array_map(fn (int $i): string => "https://o{$i}.example.org", range(1, 101))])->assertStatus(422)->assertJsonPath('errors.allowedOrigins.0.code', 'tooMany');

        $this->assertSame([], $this->stored());
    }

    public function test_a_stored_bare_host_name_is_returned_and_must_be_replaced_before_saving(): void
    {
        $this->asAdmin();
        $this->redis()->hMSet('CORS_SETTINGS', ['allowed_origins' => 'portal.example.org, https://ok.example.org', 'allowed_methods' => 'GET, POST']);

        $this->getJson(self::URI)->assertOk()->assertJsonPath('data.allowedOrigins', ['portal.example.org', 'https://ok.example.org']);
        $errors = (array) $this->send('PATCH', self::URI, ['allowedMethods' => ['GET']])->assertStatus(422)->json('errors');
        $this->assertSame(['format' => 'origin'], $errors['allowedOrigins.0'][0]['params']);

        $this->send('PATCH', self::URI, ['allowedOrigins' => ['https://portal.example.org'], 'allowedMethods' => ['GET']])->assertOk();
        $this->assertSame(['allowed_origins' => 'https://portal.example.org', 'allowed_methods' => 'GET'], $this->stored());
    }

    public function test_an_empty_origin_list_means_the_server_origin(): void
    {
        $this->asAdmin();
        $this->redis()->hMSet('CORS_SETTINGS', ['allowed_origins' => 'https://a.example.org', 'allowed_methods' => 'GET']);

        $this->send('PATCH', self::URI, ['allowedOrigins' => []])->assertOk()->assertJsonPath('data.allowedOrigins', [])->assertJsonPath('data.serverOrigin', 'https://mail.example.org');

        $this->assertSame('', $this->stored()['allowed_origins']);
    }

    public function test_changes_are_audited_and_an_outdated_tag_is_refused(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson(self::URI)->json('data.etag');
        $this->send('PATCH', self::URI, ['allowedMethods' => ['GET']])->assertOk();

        $this->send('PATCH', self::URI, ['allowedMethods' => ['POST']], ['If-Match' => $etag])->assertStatus(412);

        $records = $this->successesOf('legacyApiCorsUpdate');
        $this->assertCount(1, $records);
        $this->assertSame(['old' => ['GET', 'POST', 'PUT', 'DELETE'], 'new' => ['GET']], $records[0]->details['changes']['allowedMethods']);
    }
}
