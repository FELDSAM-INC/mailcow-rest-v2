<?php

namespace Tests\Feature\Audit;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T087 (AS US7-1, FR-066, FR-068, FR-069): every change and every refusal after
 * authentication writes exactly one record per affected account, findable by target; CSRF failures,
 * `401`s and reads write none.
 */
class AccountAuditTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->ensureDomain('a.example');
        $this->mailcow()->ensureDomain('b.example');
        $this->mailcow()->ensureDomain('c.example');
        $this->mailcow()->admin('alice', 'x');
        $this->mailcow()->domainAdmin('reseller1', ['a.example', 'b.example']);
    }

    public function test_a_change_of_domains_and_password_is_one_record_without_the_password(): void
    {
        $this->signInAs('alice', Role::Admin);

        $this->patchDomainAdmin('reseller1', ['domains' => ['b.example', 'c.example'], 'password' => 'S3cret-Passw0rd!'])->assertOk();

        $record = $this->assertAudit('domainAdminUpdate', 'success', 'domainAdmin', 'reseller1', [
            'domainsAdded' => ['c.example'],
            'domainsRemoved' => ['a.example'],
            'passwordChanged' => true,
        ]);
        $this->assertStringNotContainsString('S3cret', (string) json_encode($record));

        $this->patchDomainAdmin('reseller1', ['forcePasswordChange' => true])->assertOk();
        $events = $this->getJson('/api/v2/audit-events?targetType=domainAdmin&targetId=reseller1')->assertOk()->json('data');
        $this->assertCount(2, $events);
        $this->assertSame(['domainAdminUpdate'], array_values(array_unique(array_column($events, 'eventType'))));
    }

    public function test_refusals_after_authentication_are_recorded_once_with_their_reason(): void
    {
        $readOnly = $this->keyHeader($this->apiKey('alice', 'readOnly'));
        $readWrite = $this->keyHeader($this->apiKey('alice'));
        $this->signInAs('alice', Role::Admin);

        $cases = [
            [fn () => $this->patchDomainAdmin('nobody', ['active' => false]), 404, 'domainAdminUpdate', 'notFound'],
            [fn () => $this->createDomainAdmin(['username' => 'alice', 'password' => 'Passw0rd!x', 'domains' => ['a.example']]), 409, 'domainAdminCreation', 'conflict'],
            [fn () => $this->patchDomainAdmin('reseller1', ['active' => false], ['If-Match' => '"stale"']), 412, 'domainAdminUpdate', 'versionOutdated'],
            [fn () => $this->createDomainAdmin(['username' => 'r2', 'password' => 'Passw0rd!x', 'domains' => ['nope.example']]), 422, 'domainAdminCreation', 'validationFailed'],
            [fn () => $this->deleteAdmin('alice'), 409, 'adminDeletion', 'conflict'],
            [fn () => $this->postJson('/api/v2/admins', ['username' => 'bob', 'password' => 'Passw0rd!x'], $readOnly), 403, 'adminCreation', 'permissionDenied'],
            [fn () => $this->postJson('/api/v2/admins', ['username' => 'bob', 'password' => 'Passw0rd!x'], $readWrite), 403, 'adminCreation', 'permissionDenied'],
            [fn () => $this->postEmpty('/api/v2/domain-admins/reseller1/sso-token', $readOnly), 403, 'ssoTokenIssue', 'permissionDenied'],
        ];

        foreach ($cases as [$request, $status, $event, $reason]) {
            $before = DB::table('restv2_audit_events')->count();
            $request()->assertStatus($status);
            $records = DB::table('restv2_audit_events')->orderByDesc('occurred_at')->limit(DB::table('restv2_audit_events')->count() - $before)->get();
            $this->assertCount(1, $records, "{$event} {$status}");
            $this->assertSame([$event, 'failure', $reason], [$records[0]->event_type, $records[0]->outcome, $records[0]->failure_reason], "{$event} {$status}");
            $this->assertArrayHasKey('problemType', json_decode((string) $records[0]->details, true));
        }
    }

    public function test_a_restricted_session_refusal_is_recorded(): void
    {
        DB::table('admin')->where('username', 'alice')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "1"}']);
        $this->signInAs('alice', Role::Admin);

        $this->patchDomainAdmin('reseller1', ['active' => false])->assertForbidden();

        $this->assertAudit('domainAdminUpdate', 'failure', 'domainAdmin', 'reseller1', ['problemType' => '/api/v2/problems/pending-action-required']);
    }

    public function test_csrf_failures_unauthenticated_requests_and_reads_write_nothing(): void
    {
        $this->patchMerge('/api/v2/domain-admins/reseller1', ['active' => false])->assertUnauthorized();
        $this->deleteJson('/api/v2/domain-admins/reseller1', [], $this->csrf())->assertUnauthorized();
        $this->signInAs('alice', Role::Admin);
        $this->patchMerge('/api/v2/domain-admins/reseller1', ['active' => false])->assertForbidden()->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->getJson('/api/v2/domain-admins')->assertOk();
        $this->getJson('/api/v2/admins/alice')->assertOk();
        $this->getJson('/api/v2/capabilities/accounts')->assertOk();

        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }
}
