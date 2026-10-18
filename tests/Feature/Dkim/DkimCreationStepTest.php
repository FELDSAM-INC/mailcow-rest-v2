<?php

namespace Tests\Feature\Dkim;

use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use App\Auth\Role;
use App\Http\RequestContext;
use App\Services\Audit\AuditContext;
use App\Services\Dkim\DkimDnsOutcome;
use App\Services\Dkim\DkimKeyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Auth\AuthRequests;
use Tests\TestCase;

/**
 * Feature 010 T014 (research R10): domain and alias domain creation call
 * DkimKeyService::generateForNewName() as a follow-up step; it never throws, keeps an existing key
 * and writes its own dkimKeyGeneration audit record.
 */
class DkimCreationStepTest extends TestCase
{
    use AuthRequests;

    private function context(): AuditContext
    {
        return new AuditContext(
            new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::Password),
            'domainCreation',
        );
    }

    private function service(): DkimKeyService
    {
        return $this->app->make(DkimKeyService::class);
    }

    public function test_a_new_key_is_stored_published_and_audited(): void
    {
        $step = $this->service()->generateForNewName('example.org', 'dkim', 1024, $this->context());

        $this->assertSame('created', $step->outcome);
        $this->assertSame(DkimDnsOutcome::NotConfigured, $step->publication?->outcome);
        $redis = Redis::connection('mailcow');
        $this->assertSame('dkim', $redis->hget('DKIM_SELECTORS', 'example.org'));
        $this->assertNotEmpty($redis->hget('DKIM_PUB_KEYS', 'example.org'));
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', (string) $redis->hget('DKIM_PRIV_KEYS', 'dkim.example.org'));

        $record = $this->onlyAuditRecord('dkimKeyGeneration');
        $this->assertSame('success', $record->outcome);
        $this->assertSame('dkimKey', $record->target_type);
        $this->assertSame('example.org', $record->target_id);
        $this->assertSame('admin', $record->actor_username);
        $this->assertSame(RequestContext::requestId(request()), $record->request_id);
        $this->assertSame('domainCreation', $record->details['trigger']);
        $this->assertSame('dkim', $record->details['selector']);
        $this->assertSame(1024, $record->details['keyLength']);
        $this->assertFalse($record->details['overwriteRequested']);
        $this->assertSame('dkim', $record->details['new']['selector']);
        $this->assertSame(64, strlen($record->details['new']['publicKeySha256']));
        $this->assertSame(['status' => 'notConfigured', 'reasonCode' => null, 'detail' => null], $record->details['dnsPublication']);
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($record->details) ?: '');
    }

    public function test_an_existing_selector_or_public_key_is_kept_without_audit(): void
    {
        $redis = Redis::connection('mailcow');
        $redis->hset('DKIM_SELECTORS', 'a.example', 'old');
        $redis->hset('DKIM_PUB_KEYS', 'b.example', 'MIIBkept');

        $this->assertSame('alreadyExisted', $this->service()->generateForNewName('a.example', 'dkim', 1024, $this->context())->outcome);
        $this->assertSame('alreadyExisted', $this->service()->generateForNewName('b.example', 'dkim', 1024, $this->context())->outcome);

        $this->assertSame('old', $redis->hget('DKIM_SELECTORS', 'a.example'));
        $this->assertFalse((bool) $redis->hget('DKIM_PUB_KEYS', 'a.example'));
        $this->assertSame('MIIBkept', $redis->hget('DKIM_PUB_KEYS', 'b.example'));
        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'dkimKeyGeneration')->count());
        $this->ispconfig()->assertNoCalls();
    }

    public function test_an_unavailable_store_is_a_failed_step_without_exception(): void
    {
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();

        $step = $this->service()->generateForNewName('example.org', 'dkim', 1024, $this->context());

        $this->assertSame('failed', $step->outcome);
        $this->assertNull($step->publication);
        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'dkimKeyGeneration')->count());
    }

    public function test_an_unreachable_ispconfig_still_creates_the_key(): void
    {
        config([
            'restv2.dkim.dns_publication' => true,
            'restv2.ispconfig.url' => 'https://ispconfig.example:8080/remote/json.php',
            'restv2.ispconfig.username' => 'remote',
            'restv2.ispconfig.password' => 'secret',
        ]);
        $this->ispconfig()->failOn('login', 'unreachable', 'connection refused');

        $step = $this->service()->generateForNewName('example.org', 'dkim', 1024, $this->context());

        $this->assertSame('created', $step->outcome);
        $this->assertSame(DkimDnsOutcome::Failed, $step->publication?->outcome);
        $this->assertSame('unreachable', $step->publication->reasonCode);
        $record = $this->onlyAuditRecord('dkimKeyGeneration');
        $this->assertSame(['status' => 'failed', 'reasonCode' => 'unreachable', 'detail' => 'connection refused'], $record->details['dnsPublication']);
        $this->assertStringNotContainsString('secret', json_encode($record->details) ?: '');
    }
}
