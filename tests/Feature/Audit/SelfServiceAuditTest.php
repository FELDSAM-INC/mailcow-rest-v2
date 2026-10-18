<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditEventType;
use App\Auth\Role;
use App\Clients\Pushover\PushoverClient;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fakes\FakePushoverClient;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\Support\Totp\TestTotp;
use Tests\TestCase;

/**
 * T115, T117 (feature 005 FR-073, FR-074, US11 AS-1 to AS-3): every change and attempt of account
 * self-service is in the audit log, filterable by the mailbox and the target type, with the
 * documented details only.
 */
class SelfServiceAuditTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const NEW_PASSWORD = 'N3w!Passw0rd-2026';

    /** Feature 005's event types (research R22). */
    private const EVENT_TYPES = [
        'ownPasswordChange', 'ownSecondFactorEnrolment', 'ownSecondFactorRemoval', 'ownRecoveryEmailChange',
        'passwordResetRequest', 'passwordResetCompletion', 'appPasswordCreation', 'appPasswordUpdate',
        'appPasswordDeletion', 'temporaryAliasCreation', 'temporaryAliasUpdate', 'temporaryAliasDeletion',
        'sieveFilterCreation', 'sieveFilterUpdate', 'sieveFilterDeletion', 'pushNotificationSettingsUpdate',
        'pushNotificationSettingsDeletion', 'pushNotificationVerification', 'mailSettingsUpdate',
        'recentLoginsClear', 'activeSyncCacheReset', 'groupwareProfileReset', 'webmailHandOff',
    ];

    public function test_records_of_a_mailbox_and_of_a_target_type(): void
    {
        $this->asSelfServiceUser();
        $this->putJson('/api/v2/me/password', ['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD], $this->csrf())->assertOk();
        $this->postJson('/api/v2/mailboxes/anna@example.org/app-passwords', ['name' => 'Phone'], $this->csrf())->assertCreated();

        $this->asAdmin();
        $records = $this->getJson('/api/v2/audit-events?targetId=anna@example.org')->assertOk()->json('data');

        $this->assertEqualsCanonicalizing(['ownPasswordChange', 'appPasswordCreation'], array_column($records, 'eventType'));

        foreach ($records as $record) {
            $this->assertSame('anna@example.org', $record['actor']['username']);
            $this->assertNotEmpty($record['occurredAt']);
            $this->assertNotEmpty($record['clientAddress']);
            $this->assertSame('success', $record['outcome']);
        }

        $narrowed = $this->getJson('/api/v2/audit-events?targetId=anna@example.org&targetType=appPassword')->assertOk()->json('data');
        $this->assertSame(['appPasswordCreation'], array_column($narrowed, 'eventType'));
    }

    public function test_a_reset_request_for_an_unknown_address_is_recorded(): void
    {
        $this->configureResetMessage();
        $token = (string) $this->getJson('/api/v2/csrf-token')->assertOk()->json('data.token');
        $this->postJson('/api/v2/password-resets', ['address' => 'nobody@example.org'], ['X-CSRF-Token' => $token])->assertOk();

        $this->asAdmin();
        $records = $this->getJson('/api/v2/audit-events?eventType=passwordResetRequest')->assertOk()->json('data');

        $this->assertCount(1, $records);
        $this->assertSame('failure', $records[0]['outcome']);
        $this->assertSame('unknownAccount', $records[0]['failureReason']);
        $this->assertSame('nobody@example.org', $records[0]['target']['id']);
    }

    public function test_a_mailbox_user_cannot_read_the_log(): void
    {
        $this->asSelfServiceUser();

        $this->getJson('/api/v2/audit-events')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
    }

    /**
     * Every event type of the feature is written by its operation, validates against the contract
     * (the list response is checked against `AuditEvent`, `AuditTarget` and `AuditFailureReason`)
     * and carries only its documented detail keys.
     */
    public function test_every_event_type_is_recorded_with_documented_details(): void
    {
        $this->app->instance(PushoverClient::class, new FakePushoverClient);
        $this->configureResetMessage();
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->sogoData('anna@example.org');
        $base = '/api/v2/mailboxes/anna@example.org';

        // The mailbox user's own account and objects.
        $this->asSelfServiceUser();
        $this->putJson('/api/v2/me/recovery-email', ['address' => 'anna.private@example.net', 'currentPassword' => MailcowFactory::PASSWORD], $this->csrf())->assertOk();
        $secret = (string) $this->postEmpty('/api/v2/me/second-factors/totp-enrolment', $this->csrf())->assertOk()->json('data.secret');
        $this->postJson('/api/v2/me/second-factors', ['type' => 'totp', 'name' => 'Phone', 'code' => TestTotp::code($secret), 'currentPassword' => MailcowFactory::PASSWORD], $this->csrf())->assertCreated();
        $this->postJson('/api/v2/me/second-factors/removal', ['currentPassword' => MailcowFactory::PASSWORD], $this->csrf())->assertNoContent();
        $appPassword = (int) $this->postJson($base.'/app-passwords', ['name' => 'Phone'], $this->csrf())->assertCreated()->json('data.id');
        $this->patchMerge($base.'/app-passwords/'.$appPassword, ['name' => 'Tablet'], $this->csrf())->assertOk();
        $this->deleteJson($base.'/app-passwords/'.$appPassword, [], $this->csrf())->assertNoContent();
        $alias = (string) $this->postJson($base.'/temporary-aliases', ['domain' => 'example.org'], $this->csrf())->assertCreated()->json('data.address');
        $this->patchMerge($base.'/temporary-aliases/'.$alias, ['permanent' => true], $this->csrf())->assertOk();
        $this->deleteJson($base.'/temporary-aliases/'.$alias, [], $this->csrf())->assertNoContent();
        $this->putJson($base.'/push-notifications', ['token' => str_repeat('t', 30), 'userKey' => str_repeat('u', 30)], $this->csrf())->assertOk();
        $this->postEmpty($base.'/push-notifications/verification', $this->csrf())->assertOk();
        $this->deleteJson($base.'/push-notifications', [], $this->csrf())->assertNoContent();
        $this->patchMerge($base.'/mail-settings', ['taggedMailHandling' => 'subfolder'], $this->csrf())->assertOk();
        $this->deleteJson($base.'/recent-logins', [], $this->csrf())->assertNoContent();
        $this->deleteJson($base.'/activesync-cache', [], $this->csrf())->assertNoContent();
        $this->deleteJson($base.'/groupware-profile', [], $this->csrf());
        $this->postEmpty('/api/v2/me/webmail-handoff', $this->csrf())->assertOk();
        $this->putJson('/api/v2/me/password', ['currentPassword' => MailcowFactory::PASSWORD, 'newPassword' => self::NEW_PASSWORD], $this->csrf())->assertOk();

        // The administrator's filters.
        $this->asAdmin();
        $filter = (int) $this->postJson($base.'/sieve-filters', ['description' => 'Keep', 'kind' => 'prefilter', 'script' => 'keep;'], $this->csrf())->assertCreated()->json('data.id');
        $this->patchMerge($base.'/sieve-filters/'.$filter, ['active' => false], $this->csrf())->assertOk();
        $this->deleteJson($base.'/sieve-filters/'.$filter, [], $this->csrf())->assertNoContent();

        // The anonymous reset flow.
        $this->forgetCookies();
        $token = (string) $this->getJson('/api/v2/csrf-token')->assertOk()->json('data.token');
        $this->postJson('/api/v2/password-resets', ['address' => 'anna@example.org'], ['X-CSRF-Token' => $token])->assertOk();
        $this->mailcow()->resetToken('anna@example.org', 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA');
        $this->postJson('/api/v2/password-resets/completion', ['token' => 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA', 'newPassword' => 'R3set!Passw0rd-2026'], ['X-CSRF-Token' => $token])->assertOk();

        $this->signInAs('admin', Role::Admin);
        $records = $this->getJson('/api/v2/audit-events?limit=1000')->assertOk()->json('data');
        $types = array_values(array_unique(array_column($records, 'eventType')));

        $this->assertSame([], array_values(array_diff(self::EVENT_TYPES, $types)), 'Event types without a record');

        foreach ($records as $record) {
            $type = AuditEventType::from($record['eventType']);
            $this->assertSame([], array_values(array_diff(array_keys($record['details'] ?? []), $type->detailKeys())), "Undocumented detail keys of {$record['eventType']}");
        }

        $this->assertSame(count(self::EVENT_TYPES), count(array_filter(AuditEventType::cases(), fn (AuditEventType $t): bool => in_array($t->value, self::EVENT_TYPES, true))));
        $this->assertGreaterThan(0, DB::table('restv2_audit_events')->where('event_type', 'webmailHandOff')->count());
    }
}
