<?php

namespace Tests\Feature\Audit;

use App\Audit\AuditEventType;
use App\Auth\AccountKind;
use App\Auth\ApiKeyReference;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\RequestContext;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * T031 (FR-046, FR-048, R22): audit records are complete, written in the audited change's
 * transaction, and immutable.
 */
class AuditLoggerTest extends TestCase
{
    private const REQUEST_ID = '4bf92f3577b34da6a3ce929d0e0e4736';

    private function bindRequest(string $ip = '192.0.2.10', string $userAgent = 'Mozilla/5.0 test'): void
    {
        $request = Request::create('/api/v2/session', 'POST', server: [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => $userAgent,
        ]);
        RequestContext::setRequestId($request, self::REQUEST_ID);
        $this->app->instance('request', $request);
    }

    private function logger(): AuditLogger
    {
        return $this->app->make(AuditLogger::class);
    }

    public function test_it_writes_every_column(): void
    {
        $this->bindRequest(userAgent: str_repeat('U', 600));
        $this->travelTo(CarbonImmutable::parse('2026-10-18 12:34:56.123456', 'UTC'));

        $this->logger()->record(
            AuditEventType::ApiKeyCreation,
            true,
            new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::Password),
            ['type' => 'apiKey', 'id' => '01J00000000000000000000000', 'name' => 'billing'],
            'password',
            null,
            ['ownerUsername' => 'reseller', 'accessLevel' => 'readOnly', 'expiresAt' => null, 'allowedNetworks' => []],
        );

        $row = (array) DB::table('restv2_audit_events')->sole();

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $row['id']);
        $this->assertSame('2026-10-18 12:34:56.123456', $row['occurred_at']);
        $this->assertSame('apiKeyCreation', $row['event_type']);
        $this->assertSame('success', $row['outcome']);
        $this->assertSame('admin', $row['actor_username']);
        $this->assertSame('admin', $row['actor_role']);
        $this->assertNull($row['actor_api_key_id']);
        $this->assertSame('apiKey', $row['target_type']);
        $this->assertSame('01J00000000000000000000000', $row['target_id']);
        $this->assertSame('billing', $row['target_name']);
        $this->assertSame('192.0.2.10', $row['client_address']);
        $this->assertSame(str_repeat('U', 512), $row['user_agent']);
        $this->assertSame('password', $row['auth_method']);
        $this->assertNull($row['failure_reason']);
        $this->assertSame(self::REQUEST_ID, $row['request_id']);
        $this->assertSame(
            ['ownerUsername' => 'reseller', 'accessLevel' => 'readOnly', 'expiresAt' => null, 'allowedNetworks' => []],
            json_decode($row['details'], true),
        );
    }

    public function test_a_failed_sign_in_records_the_attempted_username(): void
    {
        $this->bindRequest();

        $this->logger()->record(AuditEventType::SignIn, false, ['username' => 'nobody'], null, 'password', 'invalidCredentials', [
            'requestedRole' => null,
            'step' => 'password',
        ]);

        $event = AuditEvent::query()->sole();
        $this->assertSame('failure', $event->outcome);
        $this->assertSame('nobody', $event->actor_username);
        $this->assertNull($event->actor_role);
        $this->assertNull($event->target_type);
        $this->assertSame('invalidCredentials', $event->failure_reason);
        $this->assertSame(['requestedRole' => null, 'step' => 'password'], $event->details);
    }

    public function test_an_api_key_actor_records_the_key(): void
    {
        $this->bindRequest();
        $principal = new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::ApiKey, apiKey: new ApiKeyReference('01J0000000000000000000KEY1', 'ci', 'mcv2_abcdefg', 'readWrite'));

        $this->logger()->record(AuditEventType::SignOut, true, $principal, ['type' => 'session', 'id' => null, 'name' => null], 'apiKey', null, []);

        $event = AuditEvent::query()->sole();
        $this->assertSame('01J0000000000000000000KEY1', $event->actor_api_key_id);
        $this->assertSame('admin', $event->actor_username);
    }

    public function test_the_record_rolls_back_with_the_audited_change(): void
    {
        $this->bindRequest();

        try {
            DB::transaction(function (): void {
                DB::table('domain')->insert(['domain' => 'rollback.example']);
                $this->logger()->record(AuditEventType::SignOut, true, ['username' => 'admin'], null, null, null, []);

                throw new RuntimeException('later failure');
            });
        } catch (RuntimeException) {
        }

        $this->assertFalse(DB::table('domain')->where('domain', 'rollback.example')->exists());
        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }

    public function test_a_failed_audit_insert_fails_the_transaction(): void
    {
        // A client address longer than the column makes the strict-mode insert fail.
        $this->bindRequest(ip: str_repeat('9', 100));

        try {
            DB::transaction(function (): void {
                DB::table('domain')->insert(['domain' => 'audited.example']);
                $this->logger()->record(AuditEventType::SignOut, true, ['username' => 'admin'], null, null, null, []);
            });
            $this->fail('The transaction must fail when the audit record cannot be written.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse(DB::table('domain')->where('domain', 'audited.example')->exists());
    }

    public function test_records_are_immutable(): void
    {
        $this->bindRequest();
        $this->logger()->record(AuditEventType::SignOut, true, ['username' => 'admin'], null, null, null, []);
        $event = AuditEvent::query()->sole();

        try {
            $event->update(['outcome' => 'failure']);
            $this->fail('update() must throw');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $event->delete();
            $this->fail('delete() must throw');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('success', DB::table('restv2_audit_events')->value('outcome'));
    }

    public function test_details_outside_the_whitelist_are_dropped(): void
    {
        $this->bindRequest();

        $this->logger()->record(AuditEventType::SignIn, true, ['username' => 'admin', 'role' => 'admin'], ['type' => 'session', 'id' => null, 'name' => null], 'password', null, [
            'requestedRole' => 'admin',
            'secondFactorType' => null,
            'restricted' => false,
            'password' => 'hunter2',
            'code' => '123456',
        ]);

        $this->assertSame(
            ['requestedRole' => 'admin', 'secondFactorType' => null, 'restricted' => false],
            AuditEvent::query()->sole()->details,
        );
    }
}
