<?php

namespace Tests\Feature\Settings;

use App\Audit\AuditEventType;
use App\Auth\Role;
use App\Clients\DockerApi\DockerCallResult;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Audit\FlakyAuditLogger;
use Tests\Support\MassMail\MassMailTestHelpers;
use Tests\Support\Settings\UsesSharedFiles;
use Tests\TestCase;

/**
 * Feature 013 T089 (FR-086 to FR-088, AS-8.1 to AS-8.3, SC-012; conventions "Refusal auditing"):
 * every change of the system settings writes exactly one success record with its event type,
 * target, actor, client address, request ID and whitelisted details; every refusal of an
 * authenticated caller exactly one failure record with its reason; CSRF failures, unauthenticated
 * requests, reads, previews, validate-only requests and no-ops none. The records are found with
 * feature 002's target filters, and a failed audit insert fails the change without leaving it.
 */
class SettingsAuditTest extends TestCase
{
    use MassMailTestHelpers;
    use UsesSharedFiles;

    private const TRACE = '4bf92f3577b34da6a3ce929d0e0e4736';

    private const PNG = '@png';

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->extraMailbox('alice@example.org');
        $this->mailcow()->guid(str_repeat('ab', 32));
        $this->sharedValue('MAIN_LOGO', 'data:image/png;base64,'.base64_encode($this->png()));
        Http::fake(['*' => Http::response(['response' => 'invalid'])]);
    }

    private function png(): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/logos/valid/logo-300x80.png'));
    }

    /**
     * operation => [method, path, body, status, event type, target type, target id, required detail
     * keys]. `{rule}` is an existing settings rule, `{running}` a running mass mail job and
     * `{created}` the identifier the response returns.
     *
     * @return array<string, array{string, string, array<string, mixed>|string|null, int, string, string, string|null, list<string>}>
     */
    public static function changes(): array
    {
        return [
            'replaceLogo' => ['PUT', '/api/v2/settings/logos/dark', self::PNG, 200, 'logoReplacement', 'logo', 'dark', ['old', 'new']],
            'deleteLogo' => ['DELETE', '/api/v2/settings/logos/light', null, 204, 'logoDeletion', 'logo', 'light', ['old']],
            'updateUiTexts' => ['PATCH', '/api/v2/settings/ui-texts', ['productName' => 'Acme'], 200, 'uiTextsUpdate', 'uiTexts', null, ['changes']],
            'updateLoginPageSettings' => ['PATCH', '/api/v2/settings/login-page', ['hideAdminLogin' => true], 200, 'loginPageUpdate', 'loginPage', null, ['changes']],
            'updateAppLinkSettings' => ['PATCH', '/api/v2/settings/app-links', ['links' => [['name' => 'Wiki', 'url' => '/wiki', 'signedInUrl' => null, 'showOnLoginPage' => true]]], 200, 'appLinksUpdate', 'appLinks', null, ['changes']],
            'updateBrandColors' => ['PATCH', '/api/v2/settings/brand-colors', ['light' => '#1d4ed8'], 200, 'brandColorsUpdate', 'brandColors', null, ['changes']],
            'updateIpCheckSetting' => ['PATCH', '/api/v2/settings/ip-check', ['enabled' => true], 200, 'ipCheckUpdate', 'ipCheck', null, ['changes']],
            'updatePasswordPolicy' => ['PATCH', '/api/v2/password-policy', ['minLength' => 8], 200, 'passwordPolicyUpdate', 'passwordPolicy', null, ['changes']],
            'updatePasswordResetNotification' => ['PATCH', '/api/v2/settings/password-reset-notification', ['subject' => 'Reset'], 200, 'passwordResetNotificationUpdate', 'passwordResetNotification', null, ['changes']],
            'createRspamdSettingsRule' => ['POST', '/api/v2/settings/rspamd-rules', ['description' => 'New', 'content' => 'priority = 1;'], 201, 'rspamdSettingsRuleCreation', 'rspamdSettingsRule', '{created}', ['changes']],
            'updateRspamdSettingsRule' => ['PATCH', '/api/v2/settings/rspamd-rules/{rule}', ['active' => true], 200, 'rspamdSettingsRuleUpdate', 'rspamdSettingsRule', '{rule}', ['changes']],
            'deleteRspamdSettingsRule' => ['DELETE', '/api/v2/settings/rspamd-rules/{rule}', null, 204, 'rspamdSettingsRuleDeletion', 'rspamdSettingsRule', '{rule}', ['changes']],
            'replaceRspamdRegexMap' => ['PUT', '/api/v2/settings/rspamd-regex-maps/global_rcpt_blacklist.map', ['content' => "/x/i\n"], 200, 'rspamdRegexMapReplacement', 'rspamdRegexMap', 'global_rcpt_blacklist.map', ['oldBytes', 'newBytes']],
            'replaceGlobalSieveFilter' => ['PUT', '/api/v2/settings/global-sieve-filters/postfilter', ['script' => "keep;\n", 'deferRestart' => true], 200, 'globalSieveFilterReplacement', 'globalSieveFilter', 'postfilter', ['oldBytes', 'newBytes', 'dovecotRestart']],
            'updateQuotaNotificationSettings' => ['PATCH', '/api/v2/settings/quota-notification', ['subject' => 'Full'], 200, 'quotaNotificationUpdate', 'quotaNotification', null, ['changes']],
            'updateQuotaNotificationRecipients' => ['PATCH', '/api/v2/domains/example.org/quota-notification-recipients', ['recipients' => ['it@example.org'], 'active' => true], 200, 'quotaNotificationRecipientsUpdate', 'quotaNotificationRecipients', 'example.org', ['changes']],
            'createMassMailJob' => ['POST', '/api/v2/mass-mail-jobs', ['sender' => 'noreply@mail.example.org', 'subject' => 'Hi', 'textBody' => 'Hello', 'selection' => ['include' => ['alice@example.org']], 'expectedRecipients' => 1], 201, 'massMailJobCreation', 'massMailJob', '{created}', ['sender', 'subject', 'selection', 'recipientCount']],
            'cancelMassMailJob' => ['POST', '/api/v2/mass-mail-jobs/{running}/cancellation', null, 200, 'massMailJobCancellation', 'massMailJob', '{running}', ['counts']],
            'setRspamdUiPassword' => ['PUT', '/api/v2/settings/rspamd-ui-password', ['password' => 'Moo pass 1'], 204, 'rspamdUiPasswordChange', 'rspamdUiPassword', null, ['passwordChanged', 'rspamdConfirmed']],
            'updateLegacyApiCorsSettings' => ['PATCH', '/api/v2/settings/legacy-api-cors', ['allowedMethods' => ['GET']], 200, 'legacyApiCorsUpdate', 'legacyApiCors', null, ['changes']],
            'verifyLicense' => ['POST', '/api/v2/license/verification', null, 200, 'licenseVerification', 'license', null, ['result']],
        ];
    }

    /**
     * @param  array<string, mixed>|string|null  $body
     * @param  list<string>  $detailKeys
     */
    #[DataProvider('changes')]
    public function test_a_change_writes_exactly_one_success_record(string $method, string $path, array|string|null $body, int $status, string $event, string $targetType, ?string $targetId, array $detailKeys): void
    {
        $this->asAdmin();
        $values = ['{rule}' => (string) $this->mailcow()->settingsRule('Rule', 'x = 1;', 0)];

        if (str_contains($path, '{running}')) {
            $values['{running}'] = $this->runningJob(['alice@example.org']);
        }

        $path = strtr($path, $values);
        $before = $this->auditIds();
        $headers = ['traceparent' => '00-'.self::TRACE.'-00f067aa0ba902b7-01'];

        $response = $body === self::PNG ? $this->sendRaw($method, $path, $this->png(), 'image/png', $headers) : $this->send($method, $path, is_array($body) ? $body : null, $headers);

        $response->assertStatus($status);
        $records = $this->recordsSince($before);
        $this->assertCount(1, $records, 'exactly one record');
        $record = $records[0];
        $expectedId = match ($targetId) {
            null => null,
            '{created}' => (string) $response->json('data.id'),
            default => strtr($targetId, $values),
        };
        $this->assertSame([$event, 'success', null, $targetType, $expectedId, 'admin', 'admin', self::TRACE], [
            $record->event_type, $record->outcome, $record->failure_reason, $record->target_type, $record->target_id, $record->actor_username, $record->actor_role, $record->request_id,
        ]);
        $this->assertNotSame('', (string) $record->client_address);

        foreach ($detailKeys as $key) {
            $this->assertArrayHasKey($key, $record->details);
        }

        $this->assertSame([], array_values(array_diff(array_keys($record->details), AuditEventType::from($event)->detailKeys())), 'only whitelisted details');
    }

    public function test_every_refusal_reason_writes_exactly_one_failure_record(): void
    {
        $this->mailcow()->domain('foreign.org');
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->mailcow()->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $readWrite = $this->keyHeader($this->apiKey('admin'));
        $readOnly = $this->keyHeader($this->apiKey('admin', 'readOnly'));
        $job = $this->runningJob(['alice@example.org']);

        $this->asDomainAdmin(['example.org']);
        $this->assertRefusal(fn () => $this->send('PATCH', '/api/v2/settings/ui-texts', ['productName' => 'X']), 'uiTextsUpdate', 'permissionDenied', 'forbidden');
        $this->assertRefusal(fn () => $this->send('PATCH', '/api/v2/domains/foreign.org/quota-notification-recipients', ['active' => true]), 'quotaNotificationRecipientsUpdate', 'notFound', null, 'foreign.org');

        $this->forgetCookies();
        $this->assertRefusal(fn () => $this->send('PATCH', '/api/v2/settings/ip-check', ['enabled' => true], $readOnly), 'ipCheckUpdate', 'permissionDenied', 'insufficient-key-scope');
        $this->assertRefusal(fn () => $this->send('PUT', '/api/v2/settings/rspamd-ui-password', ['password' => 'Moo pass 1'], $readWrite), 'rspamdUiPasswordChange', 'permissionDenied', 'session-required');
        $this->assertRefusal(fn () => $this->send('POST', '/api/v2/mass-mail-jobs', ['sender' => 'a@example.org', 'subject' => 'S', 'textBody' => 'T', 'selection' => ['include' => ['alice@example.org']], 'expectedRecipients' => 1], $readWrite), 'massMailJobCreation', 'permissionDenied', 'session-required');
        $this->assertRefusal(fn () => $this->send('POST', "/api/v2/mass-mail-jobs/{$job}/cancellation", null, $readWrite), 'massMailJobCancellation', 'permissionDenied', 'session-required', $job);

        $this->signInAs('restricted', Role::Admin);
        $this->assertRefusal(fn () => $this->send('PATCH', '/api/v2/settings/login-page', ['hideAdminLogin' => true]), 'loginPageUpdate', 'permissionDenied', 'pending-action-required');

        $this->forgetCookies();
        $this->signInAs('admin', Role::Admin);
        $this->assertRefusal(fn () => $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => '#1d4ed8'], ['If-Match' => '"outdated"']), 'brandColorsUpdate', 'versionOutdated', null);
        $record = $this->assertRefusal(fn () => $this->send('PATCH', '/api/v2/settings/ui-texts', ['productName' => "Secret\nvalue"]), 'uiTextsUpdate', 'validationFailed', 'validation-failed');
        $this->assertSame(['productName' => [['code' => 'invalidFormat', 'params' => ['format' => 'singleLine']]]], $record->details['errors']);
        $this->assertStringNotContainsString('Secret', json_encode($record->details) ?: '');
        $this->assertRefusal(fn () => $this->send('POST', '/api/v2/mass-mail-jobs', ['sender' => 'noreply@mail.example.org', 'subject' => 'S', 'textBody' => 'T', 'selection' => ['include' => ['alice@example.org']], 'expectedRecipients' => 1]), 'massMailJobCreation', 'conflict', 'mass-mail-already-running');
        $this->files->removeMap('bad_words.map');
        $this->assertRefusal(fn () => $this->send('PUT', '/api/v2/settings/rspamd-regex-maps/bad_words.map', ['content' => "/x/i\n"]), 'rspamdRegexMapReplacement', 'fileUnavailable', 'regex-map-unavailable', 'bad_words.map');

        config(['restv2.demo_mode' => true]);
        $this->assertRefusal(fn () => $this->send('PATCH', '/api/v2/settings/ip-check', ['enabled' => true]), 'ipCheckUpdate', 'demoMode', 'demo-mode');
        config(['restv2.demo_mode' => false, 'restv2.license.enabled' => false]);
        $this->assertRefusal(fn () => $this->send('POST', '/api/v2/license/verification'), 'licenseVerification', 'featureDisabled', 'feature-disabled');

        $this->dockerApi()->respondTo('exec', DockerCallResult::failed('rspamadm failed'));
        $this->assertRefusal(fn () => $this->send('PUT', '/api/v2/settings/rspamd-ui-password', ['password' => 'Moo pass 1']), 'rspamdUiPasswordChange', 'serviceFailure', 'container-action-failed');
        $this->dockerApi()->failNext('exec', 'timeout');
        $this->assertRefusal(fn () => $this->send('PUT', '/api/v2/settings/rspamd-ui-password', ['password' => 'Moo pass 1']), 'rspamdUiPasswordChange', 'outcomeUnknown', 'outcome-unknown');

        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();
        $this->assertRefusal(fn () => $this->send('PATCH', '/api/v2/settings/quota-notification', ['subject' => 'Full']), 'quotaNotificationUpdate', 'temporaryFailure', 'temporary-failure');
    }

    public function test_csrf_failures_and_unauthenticated_requests_are_not_recorded(): void
    {
        $this->patchMerge('/api/v2/settings/ip-check', ['enabled' => true])->assertUnauthorized();
        $this->putJson('/api/v2/settings/rspamd-ui-password', ['password' => 'Moo pass 1'])->assertUnauthorized();
        $this->asAdmin();
        $this->patchMerge('/api/v2/settings/ip-check', ['enabled' => true], ['X-CSRF-Token' => 'wrong'])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->putJson('/api/v2/settings/rspamd-ui-password', ['password' => 'Moo pass 1'], ['X-CSRF-Token' => 'wrong'])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');

        $this->assertSame([], $this->auditOf('ipCheckUpdate'));
        $this->assertSame([], $this->auditOf('rspamdUiPasswordChange'));
        $this->assertNull($this->sharedGet('IP_CHECK'));
        $this->dockerApi()->assertNoAction();
    }

    public function test_reads_previews_validate_only_requests_and_no_ops_are_not_recorded(): void
    {
        $this->asAdmin();
        $before = $this->auditIds();

        foreach (['/api/v2/settings/ui-texts', '/api/v2/settings/ip-check', '/api/v2/settings/legacy-api-cors', '/api/v2/license', '/api/v2/settings/rspamd-rules', '/api/v2/settings/global-sieve-filters', '/api/v2/mass-mail-jobs', '/api/v2/capabilities/settings'] as $read) {
            $this->getJson($read)->assertOk();
        }

        $this->send('POST', '/api/v2/mass-mail-jobs/recipient-preview', ['selection' => ['include' => ['alice@example.org']]])->assertOk();
        $this->send('POST', '/api/v2/settings/rspamd-rules?validateOnly=true', ['description' => 'New', 'content' => 'priority = 1;'])->assertOk();
        $this->send('POST', '/api/v2/settings/rspamd-rules?validateOnly=true', ['description' => 'New', 'content' => 'apply {'])->assertStatus(422);
        $this->send('PUT', '/api/v2/settings/global-sieve-filters/prefilter?validateOnly=true', ['script' => 'if true { keep }'])->assertStatus(422);
        $this->send('PUT', '/api/v2/settings/rspamd-regex-maps/bad_words.map?validateOnly=true', ['content' => "/x/i\n"])->assertOk();
        $this->send('PATCH', '/api/v2/settings/ip-check', ['enabled' => false])->assertOk();
        $this->send('PATCH', '/api/v2/settings/legacy-api-cors', ['allowedMethods' => ['GET', 'POST', 'PUT', 'DELETE']])->assertOk();
        $this->send('PATCH', '/api/v2/settings/brand-colors', ['light' => null])->assertOk();

        $this->assertSame([], array_map(fn (\stdClass $r): string => $r->event_type, $this->recordsSince($before)));
    }

    public function test_a_regex_map_change_is_found_by_its_target_with_its_sizes(): void
    {
        $this->asAdmin();
        $old = strlen($this->files->mapContent('global_rcpt_blacklist.map'));
        $this->send('PUT', '/api/v2/settings/rspamd-regex-maps/global_rcpt_blacklist.map', ['content' => "/spam@example\\.net/i\n"])->assertOk();

        $events = (array) $this->getJson('/api/v2/audit-events?targetType=rspamdRegexMap&targetId=global_rcpt_blacklist.map')->assertOk()->json('data');

        $this->assertCount(1, $events);
        $this->assertSame('rspamdRegexMapReplacement', $events[0]['eventType']);
        $this->assertSame(['oldBytes' => $old, 'newBytes' => strlen("/spam@example\\.net/i\n")], $events[0]['details']);
        $this->assertSame('rspamdRegexMap', $events[0]['target']['type']);
    }

    public function test_a_mass_mail_job_yields_a_creation_and_a_completion_record(): void
    {
        $this->asAdmin();
        $id = (string) $this->send('POST', '/api/v2/mass-mail-jobs', ['sender' => 'noreply@mail.example.org', 'subject' => 'Hi', 'textBody' => 'Hello', 'selection' => ['include' => ['alice@example.org']], 'expectedRecipients' => 1])
            ->assertCreated()->json('data.id');

        $this->artisan('restv2:mass-mail-run', ['--max-time' => 30])->assertSuccessful();

        $events = (array) $this->getJson("/api/v2/audit-events?targetType=massMailJob&targetId={$id}")->assertOk()->json('data');
        $types = array_column($events, 'eventType');
        sort($types);
        $this->assertSame(['massMailJobCompletion', 'massMailJobCreation'], $types);
        $completion = array_values(array_filter($events, fn (array $e): bool => $e['eventType'] === 'massMailJobCompletion'))[0];
        $creation = array_values(array_filter($events, fn (array $e): bool => $e['eventType'] === 'massMailJobCreation'))[0];
        $this->assertSame(['username' => null, 'role' => null, 'apiKeyId' => null], $completion['actor']);
        $this->assertSame($creation['requestId'], $completion['requestId']);
        $this->assertSame(['pending' => 0, 'delivered' => 1, 'failed' => 0, 'skipped' => 0], $completion['details']['counts']);
    }

    public function test_a_failed_audit_insert_fails_the_change_and_leaves_nothing_changed(): void
    {
        $this->asAdmin();
        $map = $this->files->mapContent('global_rcpt_blacklist.map');
        $rules = DB::table('settingsmap')->count();
        $this->app->instance(AuditLogger::class, new FlakyAuditLogger(failingTypes: [
            AuditEventType::IpCheckUpdate, AuditEventType::RspamdRegexMapReplacement, AuditEventType::RspamdSettingsRuleCreation, AuditEventType::QuotaNotificationRecipientsUpdate,
        ]));

        $this->send('PATCH', '/api/v2/settings/ip-check', ['enabled' => true])->assertStatus(500);
        $this->send('PUT', '/api/v2/settings/rspamd-regex-maps/global_rcpt_blacklist.map', ['content' => "/x/i\n"])->assertStatus(500);
        $this->send('POST', '/api/v2/settings/rspamd-rules', ['description' => 'New', 'content' => 'priority = 1;'])->assertStatus(500);
        $this->send('PATCH', '/api/v2/domains/example.org/quota-notification-recipients', ['recipients' => ['it@example.org']])->assertStatus(500);

        $this->assertNull($this->sharedGet('IP_CHECK'));
        $this->assertSame($map, $this->files->mapContent('global_rcpt_blacklist.map'));
        $this->assertSame($rules, DB::table('settingsmap')->count());
        $this->assertSame(0, DB::table('restv2_audit_events')->where('outcome', 'success')->whereIn('event_type', ['ipCheckUpdate', 'rspamdRegexMapReplacement', 'rspamdSettingsRuleCreation', 'quotaNotificationRecipientsUpdate'])->count());
        $this->assertFalse($this->redis()->hExists('QW_BCC', 'example.org'));
    }

    /**
     * Runs a refused request and checks that it wrote exactly one failure record.
     *
     * @param  callable(): TestResponse<Response>  $request
     */
    private function assertRefusal(callable $request, string $event, string $reason, ?string $problemType, ?string $targetId = null): \stdClass
    {
        $before = $this->auditIds();
        $response = $request();
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());

        if ($problemType !== null) {
            $response->assertJsonPath('type', '/api/v2/problems/'.$problemType);
        }

        $records = $this->recordsSince($before);
        $this->assertCount(1, $records, "exactly one record for {$event} ({$reason})");
        $this->assertSame([$event, 'failure', $reason], [$records[0]->event_type, $records[0]->outcome, $records[0]->failure_reason]);
        $this->assertSame($problemType === null ? 'about:blank' : '/api/v2/problems/'.$problemType, $records[0]->details['problemType']);

        if ($targetId !== null) {
            $this->assertSame($targetId, $records[0]->target_id);
        }

        return $records[0];
    }

    /**
     * @return list<string>
     */
    private function auditIds(): array
    {
        return DB::table('restv2_audit_events')->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();
    }

    /**
     * Audit records written after the given snapshot, with decoded details.
     *
     * @param  list<string>  $before
     * @return list<\stdClass>
     */
    private function recordsSince(array $before): array
    {
        return DB::table('restv2_audit_events')->whereNotIn('id', $before === [] ? [''] : $before)->orderBy('occurred_at')->orderBy('id')->get()
            ->map(function (\stdClass $row): \stdClass {
                $row->details = json_decode((string) $row->details, true);

                return $row;
            })->values()->all();
    }
}
