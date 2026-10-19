<?php

namespace Tests\Feature\Settings;

use App\Exceptions\ProblemException;
use App\Services\Settings\SettingsAuditTargetResolver;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Settings\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Feature 013 T013 (research R24, docs/conventions.md "Refusal auditing"): the shared refusal
 * auditor maps the problem types of the system settings to their failure reasons, skips refused
 * validate-only requests (feature 006's `skipWhenQueryTrue`), and names the target of each of the
 * 17 target types of this feature through SettingsAuditTargetResolver. Feature 008's own
 * RefusalAuditorTest keeps covering the shared mechanism.
 */
class SettingsRefusalAuditorTest extends TestCase
{
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $action = function (Request $request): never {
            throw match ($request->input('problem')) {
                'demoMode' => new ProblemException(ProblemType::DemoMode),
                'featureDisabled' => new ProblemException(ProblemType::FeatureDisabled),
                'regexMap' => new ProblemException(ProblemType::RegexMapUnavailable),
                'sieveFile' => new ProblemException(ProblemType::GlobalSieveFilterUnavailable),
                'running' => new ProblemException(ProblemType::MassMailAlreadyRunning, null, ['runningJobId' => '01JABCDEFGHJKMNPQRSTVWXYZ0']),
                'count' => new ProblemException(ProblemType::MassMailRecipientCountChanged, null, ['actualRecipients' => 3]),
                default => new ProblemException(ProblemType::Forbidden),
            };
        };

        Route::middleware(['api.v2', 'restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])
            ->post('/api/v2/__testing/settings-refusals/{variant}', $action)
            ->defaults('restv2.audit', ['event' => 'logoReplacement', 'target' => 'logo', 'skipWhenQueryTrue' => 'validateOnly']);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function reasons(): array
    {
        return [
            'demo mode' => ['demoMode', 'demoMode', '/api/v2/problems/demo-mode'],
            'feature disabled' => ['featureDisabled', 'featureDisabled', '/api/v2/problems/feature-disabled'],
            'regex map unavailable' => ['regexMap', 'fileUnavailable', '/api/v2/problems/regex-map-unavailable'],
            'Sieve file unavailable' => ['sieveFile', 'fileUnavailable', '/api/v2/problems/global-sieve-filter-unavailable'],
            'mass mail running' => ['running', 'conflict', '/api/v2/problems/mass-mail-already-running'],
            'recipient count changed' => ['count', 'conflict', '/api/v2/problems/mass-mail-recipient-count-changed'],
        ];
    }

    #[DataProvider('reasons')]
    public function test_each_settings_problem_is_recorded_with_its_failure_reason(string $problem, string $reason, string $type): void
    {
        $this->asAdmin();

        $this->postJson('/api/v2/__testing/settings-refusals/light', ['problem' => $problem], $this->csrf())->assertStatus(ProblemType::fromUri($type)?->status() ?? 0);

        $records = $this->auditOf('logoReplacement');
        $this->assertCount(1, $records);
        $this->assertSame(['failure', $reason, $type], [$records[0]->outcome, $records[0]->failure_reason, $records[0]->details['problemType']]);
        $this->assertSame(['logo', 'light'], [$records[0]->target_type, $records[0]->target_id]);
    }

    public function test_a_refused_validate_only_request_writes_no_record(): void
    {
        $this->asAdmin();

        $this->postJson('/api/v2/__testing/settings-refusals/light?validateOnly=true', ['problem' => 'demoMode'], $this->csrf())->assertForbidden();

        $this->assertSame([], $this->auditOf('logoReplacement'));
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array{type: string, id: string|null, name: string|null}
     */
    private function resolve(string $type, array $parameters = []): array
    {
        $request = Request::create('/api/v2/x', 'PATCH');
        $route = (new RoutingRoute('PATCH', 'api/v2/x', fn () => null))->bind($request);

        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(fn () => $route);

        return SettingsAuditTargetResolver::target($type, $request);
    }

    public function test_the_singleton_settings_have_no_identifier(): void
    {
        foreach (['uiTexts', 'loginPage', 'appLinks', 'brandColors', 'ipCheck', 'passwordPolicy', 'passwordResetNotification', 'quotaNotification', 'rspamdUiPassword', 'legacyApiCors', 'license'] as $type) {
            $this->assertSame(['type' => $type, 'id' => null, 'name' => null], $this->resolve($type), $type);
        }
    }

    public function test_the_objects_are_named_from_the_route(): void
    {
        $ruleId = $this->mailcow()->settingsRule('Disable Rspamd for example.org', 'rcpt = "/.*@example.org$/";');

        $this->assertSame(['type' => 'logo', 'id' => 'dark', 'name' => null], $this->resolve('logo', ['variant' => 'dark']));
        $this->assertSame(['type' => 'rspamdSettingsRule', 'id' => (string) $ruleId, 'name' => 'Disable Rspamd for example.org'], $this->resolve('rspamdSettingsRule', ['ruleId' => (string) $ruleId]));
        $this->assertSame(['type' => 'rspamdSettingsRule', 'id' => '999999', 'name' => null], $this->resolve('rspamdSettingsRule', ['ruleId' => '999999']));
        $this->assertSame(['type' => 'rspamdRegexMap', 'id' => 'global_rcpt_blacklist.map', 'name' => 'Recipient Denylist'], $this->resolve('rspamdRegexMap', ['mapId' => 'global_rcpt_blacklist.map']));
        $this->assertSame(['type' => 'rspamdRegexMap', 'id' => 'unknown.map', 'name' => null], $this->resolve('rspamdRegexMap', ['mapId' => 'unknown.map']));
        $this->assertSame(['type' => 'globalSieveFilter', 'id' => 'prefilter', 'name' => null], $this->resolve('globalSieveFilter', ['filterId' => 'prefilter']));
        $this->assertSame(['type' => 'quotaNotificationRecipients', 'id' => 'xn--mnchen-3ya.de', 'name' => 'münchen.de'], $this->resolve('quotaNotificationRecipients', ['domain' => 'MÜNCHEN.de']));
        $this->assertSame(255, mb_strlen((string) $this->resolve('quotaNotificationRecipients', ['domain' => str_repeat('a', 300)])['id']));
        DB::table('restv2_mass_mail_jobs')->insert([
            'id' => '01JABCDEFGHJKMNPQRSTVWXYZ0', 'status' => 'completed', 'sender' => 'a@example.org', 'subject' => 'Maintenance',
            'text_body' => 'x', 'selection' => '{}', 'recipient_count' => 1, 'created_by' => 'admin',
            'created_request_id' => str_repeat('a', 32), 'created_client_address' => '192.0.2.1', 'created_at' => '2026-10-01 10:00:00',
        ]);
        $this->assertSame(['type' => 'massMailJob', 'id' => '01JABCDEFGHJKMNPQRSTVWXYZ0', 'name' => 'Maintenance'], $this->resolve('massMailJob', ['jobId' => '01JABCDEFGHJKMNPQRSTVWXYZ0']));
    }
}
