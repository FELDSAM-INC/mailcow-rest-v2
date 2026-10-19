<?php

namespace Tests\Feature\Settings;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T083 (FR-082 to FR-085, AS-7.5 to AS-7.7): the server's GUID and its verification
 * against mailcow's license service, stored where the legacy dashboard reads it (in legacy's
 * format); the service is contacted only on request, and the operations can be disabled.
 */
class LicenseTest extends TestCase
{
    use SettingsTestHelpers;

    private const GUID = '4c6f1b2a9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1e0d9c8b7a6f5e4d3c2b';

    public function test_the_guid_is_read_and_never_verified_without_a_request(): void
    {
        Http::fake();
        $this->asAdmin();
        $this->mailcow()->guid(self::GUID);

        $this->getJson('/api/v2/license')->assertOk()
            ->assertJsonPath('data.guid', self::GUID)
            ->assertJsonPath('data.guidValid', true)
            ->assertJsonPath('data.lastVerification', ['result' => 'neverVerified', 'verifiedAt' => null, 'customerId' => null, 'serviceId' => null, 'supportLevel' => null]);

        Http::assertNothingSent();
    }

    public function test_a_guid_marked_invalid_reads_as_invalid_and_can_still_be_verified(): void
    {
        Http::fake(['*' => Http::response(['response' => 'invalid'])]);
        $this->asAdmin();
        $this->mailcow()->guid('INVALID');

        $this->getJson('/api/v2/license')->assertOk()->assertJsonPath('data.guid', null)->assertJsonPath('data.guidValid', false);
        $this->send('POST', '/api/v2/license/verification')->assertOk()->assertJsonPath('data.lastVerification.result', 'invalid');

        Http::assertSent(fn (Request $request): bool => $request['guid'] === 'INVALID');
    }

    public function test_a_valid_verification_is_stored_in_legacy_s_format(): void
    {
        $options = [];
        Http::fake(['verify.mailcow.email*' => function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response(['response' => 'ok', 'c' => 'C1', 's' => 'S1', 'm' => 'Mooore']);
        }]);
        $this->asAdmin();
        $this->mailcow()->guid(self::GUID);

        $response = $this->send('POST', '/api/v2/license/verification')->assertOk();

        $response->assertJsonPath('data.lastVerification.result', 'valid')
            ->assertJsonPath('data.lastVerification.customerId', 'C1')
            ->assertJsonPath('data.lastVerification.serviceId', 'S1')
            ->assertJsonPath('data.lastVerification.supportLevel', 3);
        $this->assertNotNull($response->json('data.lastVerification.verifiedAt'));
        $this->assertSame(json_encode(['valid' => 'true', 'c' => 'C1', 's' => 'S1', 'm' => '🐄🐄🐄']), $this->sharedGet('LICENSE_STATUS_CACHE'));
        $record = json_decode((string) $this->redis()->get('restv2:license:last-verification'), true);
        $this->assertSame(['valid', 'C1', 'S1', 3, hash('sha256', (string) $this->sharedGet('LICENSE_STATUS_CACHE'))], [$record['result'], $record['customerId'], $record['serviceId'], $record['supportLevel'], $record['cacheHash']]);
        $this->assertSame(10, $options['connect_timeout'] ?? null);
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://verify.mailcow.email' && $request->method() === 'POST' && $request->data() === ['guid' => self::GUID];
        });
        $this->getJson('/api/v2/license')->assertOk()->assertJsonPath('data.lastVerification.verifiedAt', $response->json('data.lastVerification.verifiedAt'));
        $this->assertSame(['result' => 'valid'], $this->successesOf('licenseVerification')[0]->details);
    }

    public function test_no_moore_is_one_cow(): void
    {
        Http::fake(['*' => Http::response(['response' => 'ok', 'c' => 'C', 's' => 'S', 'm' => 'NoMoore'])]);
        $this->asAdmin();
        $this->mailcow()->guid(self::GUID);

        $this->send('POST', '/api/v2/license/verification')->assertOk()->assertJsonPath('data.lastVerification.supportLevel', 1);
    }

    public function test_an_invalid_answer_stores_legacy_s_no_mark_three_times(): void
    {
        Http::fake(['*' => Http::response(['response' => 'invalid'])]);
        $this->asAdmin();
        $this->mailcow()->guid(self::GUID);

        $this->send('POST', '/api/v2/license/verification')->assertOk()->assertJsonPath('data.lastVerification.result', 'invalid')->assertJsonPath('data.lastVerification.customerId', null);

        // Legacy writes the en-gb `mailbox.no` text, the HTML entity of a cross mark.
        $this->assertSame('{"valid":"false","c":"&#10005;","s":"&#10005;","m":"&#10005;"}', $this->sharedGet('LICENSE_STATUS_CACHE'));
    }

    public function test_an_unreachable_service_or_garbage_is_unreachable_not_an_error(): void
    {
        $this->asAdmin();
        $this->mailcow()->guid(self::GUID);
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection()
            ->push('<html>oops</html>', 500)
            ->push(['response' => 'maintenance'])]);

        $this->send('POST', '/api/v2/license/verification')->assertOk()->assertJsonPath('data.lastVerification.result', 'unreachable');
        $this->assertSame('{"valid":"false","c":"Temporary error","s":"Temporary error","m":"Temporary error"}', $this->sharedGet('LICENSE_STATUS_CACHE'));
        $this->send('POST', '/api/v2/license/verification')->assertOk()->assertJsonPath('data.lastVerification.result', 'unreachable');
        $this->send('POST', '/api/v2/license/verification')->assertOk()->assertJsonPath('data.lastVerification.result', 'unreachable');

        $this->assertSame(['unreachable', 'unreachable', 'unreachable'], array_map(fn (\stdClass $r): mixed => $r->details['result'], $this->successesOf('licenseVerification')));
    }

    public function test_results_written_by_legacy_are_read(): void
    {
        $this->asAdmin();
        $this->mailcow()->guid(self::GUID);
        $german = json_decode((string) file_get_contents(base_path('tests/fixtures/mailcow/lang/lang.de-de.json')), true);
        $tempError = (string) $german['danger']['temp_error'];

        $this->sharedValue('LICENSE_STATUS_CACHE', json_encode(['valid' => 'false', 'c' => $tempError, 's' => $tempError, 'm' => $tempError]));
        $this->getJson('/api/v2/license')->assertOk()->assertJsonPath('data.lastVerification.result', 'unreachable')->assertJsonPath('data.lastVerification.verifiedAt', null);

        $this->sharedValue('LICENSE_STATUS_CACHE', json_encode(['valid' => 'false', 'c' => 'Nein', 's' => 'Nein', 'm' => 'Nein']));
        $this->getJson('/api/v2/license')->assertOk()->assertJsonPath('data.lastVerification.result', 'invalid');

        $this->sharedValue('LICENSE_STATUS_CACHE', json_encode(['valid' => 'true', 'c' => 'C9', 's' => 'S9', 'm' => '🐄🐄']));
        $this->getJson('/api/v2/license')->assertOk()
            ->assertJsonPath('data.lastVerification', ['result' => 'valid', 'verifiedAt' => null, 'customerId' => 'C9', 'serviceId' => 'S9', 'supportLevel' => 2]);
    }

    public function test_disabled_verification_refuses_both_operations_without_contacting_the_service(): void
    {
        Http::fake();
        config(['restv2.license.enabled' => false]);
        $this->asAdmin();

        $this->getJson('/api/v2/license')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/feature-disabled');
        $this->send('POST', '/api/v2/license/verification')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/feature-disabled');

        Http::assertNothingSent();
        $this->assertSame(['featureDisabled'], array_column($this->refusalsOf('licenseVerification'), 'failure_reason'));
    }

    public function test_domain_administrators_are_forbidden(): void
    {
        Http::fake();
        $this->mailcow()->domain('example.org');
        $this->asDomainAdmin(['example.org']);

        $this->getJson('/api/v2/license')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->send('POST', '/api/v2/license/verification')->assertForbidden();

        Http::assertNothingSent();
    }
}
