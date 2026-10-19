<?php

namespace Tests\Feature\Settings;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\Support\Settings\UsesSharedFiles;
use Tests\TestCase;

/**
 * Feature 013 T093 (FR-091): while mailcow's Redis cannot be reached, every Redis-backed read and
 * write of the system settings answers `503 temporary-failure` with `Retry-After`, writes no success
 * audit record, records a refused write as `temporaryFailure`, and changes no file or row; the
 * license service is not contacted.
 */
class SharedStoreUnavailableTest extends TestCase
{
    use SettingsTestHelpers;
    use UsesSharedFiles;

    /**
     * @return array<string, array{string, string, array<string, mixed>|string|null, string|null}>
     */
    public static function operations(): array
    {
        return [
            'getLogoSetting' => ['GET', '/api/v2/settings/logos/light', null, null],
            'replaceLogo' => ['PUT', '/api/v2/settings/logos/dark', '@png', 'logoReplacement'],
            'deleteLogo' => ['DELETE', '/api/v2/settings/logos/light', null, 'logoDeletion'],
            'getUiTexts' => ['GET', '/api/v2/settings/ui-texts', null, null],
            'updateUiTexts' => ['PATCH', '/api/v2/settings/ui-texts', ['productName' => 'Acme'], 'uiTextsUpdate'],
            'getLoginPageSettings' => ['GET', '/api/v2/settings/login-page', null, null],
            'updateLoginPageSettings' => ['PATCH', '/api/v2/settings/login-page', ['hideAdminLogin' => true], 'loginPageUpdate'],
            'getAppLinkSettings' => ['GET', '/api/v2/settings/app-links', null, null],
            'updateAppLinkSettings' => ['PATCH', '/api/v2/settings/app-links', ['links' => []], 'appLinksUpdate'],
            'getBrandColors' => ['GET', '/api/v2/settings/brand-colors', null, null],
            'updateBrandColors' => ['PATCH', '/api/v2/settings/brand-colors', ['light' => '#1d4ed8'], 'brandColorsUpdate'],
            'getIpCheckSetting' => ['GET', '/api/v2/settings/ip-check', null, null],
            'updateIpCheckSetting' => ['PATCH', '/api/v2/settings/ip-check', ['enabled' => true], 'ipCheckUpdate'],
            'updatePasswordPolicy' => ['PATCH', '/api/v2/password-policy', ['minLength' => 8], 'passwordPolicyUpdate'],
            'getPasswordResetNotification' => ['GET', '/api/v2/settings/password-reset-notification', null, null],
            'updatePasswordResetNotification' => ['PATCH', '/api/v2/settings/password-reset-notification', ['subject' => 'Reset'], 'passwordResetNotificationUpdate'],
            'getQuotaNotificationSettings' => ['GET', '/api/v2/settings/quota-notification', null, null],
            'updateQuotaNotificationSettings' => ['PATCH', '/api/v2/settings/quota-notification', ['subject' => 'Full'], 'quotaNotificationUpdate'],
            'getQuotaNotificationRecipients' => ['GET', '/api/v2/domains/example.org/quota-notification-recipients', null, null],
            'updateQuotaNotificationRecipients' => ['PATCH', '/api/v2/domains/example.org/quota-notification-recipients', ['recipients' => ['it@example.org']], 'quotaNotificationRecipientsUpdate'],
            'getLegacyApiCorsSettings' => ['GET', '/api/v2/settings/legacy-api-cors', null, null],
            'updateLegacyApiCorsSettings' => ['PATCH', '/api/v2/settings/legacy-api-cors', ['allowedMethods' => ['GET']], 'legacyApiCorsUpdate'],
            'getLicenseStatus' => ['GET', '/api/v2/license', null, null],
            'verifyLicense' => ['POST', '/api/v2/license/verification', null, 'licenseVerification'],
        ];
    }

    /**
     * @param  array<string, mixed>|string|null  $body
     */
    #[DataProvider('operations')]
    public function test_the_operation_answers_temporary_failure(string $method, string $path, array|string|null $body, ?string $event): void
    {
        Http::fake();
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
        $files = $this->fileHashes();
        $rows = DB::table('settingsmap')->count();
        $successes = DB::table('restv2_audit_events')->where('outcome', 'success')->count();
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $response = $body === '@png'
            ? $this->sendRaw($method, $path, (string) file_get_contents(base_path('tests/fixtures/logos/valid/logo-300x80.png')), 'image/png')
            : $this->send($method, $path, is_array($body) ? $body : null);

        $response->assertStatus(503)->assertJsonPath('type', '/api/v2/problems/temporary-failure')->assertHeader('Retry-After');
        $this->assertSame($successes, DB::table('restv2_audit_events')->where('outcome', 'success')->count());

        if ($event !== null) {
            $this->assertSame(['temporaryFailure'], array_column($this->refusalsOf($event), 'failure_reason'));
        }

        $this->assertSame($files, $this->fileHashes());
        $this->assertSame($rows, DB::table('settingsmap')->count());
        Http::assertNothingSent();
    }

    /**
     * @return array<string, string>
     */
    private function fileHashes(): array
    {
        $hashes = [];

        foreach (array_merge(glob($this->files->mapDir().'/*') ?: [], glob($this->files->sieveDir().'/*') ?: []) as $file) {
            $hashes[$file] = sha1((string) file_get_contents($file));
        }

        return $hashes;
    }
}
