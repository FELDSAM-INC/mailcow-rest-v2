<?php

namespace Tests\Feature\Dependencies;

use App\Audit\AuditEventType;
use App\Auth\Role;
use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Exceptions\ProblemException;
use App\Services\AliasDomains\AliasDomainCleanup;
use App\Services\Audit\AuditContext;
use App\Services\Audit\AuditLogger;
use App\Services\Dkim\DkimCreationStep;
use App\Services\Dkim\DkimDnsOutcome;
use App\Services\Dkim\DkimKeyService;
use App\Services\Domains\DomainUsageCalculator;
use App\Services\Groupware\GroupwareOutcome;
use App\Services\Groupware\SogoDirectory;
use App\Services\Mailboxes\AddressAvailability;
use App\Services\Mailboxes\AddressLock;
use App\Services\RateLimits\RateLimitStore;
use App\Support\Domain\DomainName;
use App\Support\Http\ProblemType;
use App\Auth\AccountKind;
use App\Auth\AuthMethod;
use App\Auth\Principal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T006 (research R24): the classes of features 001 to 003 that this feature reuses,
 * pinned with the behaviour it relies on.
 */
class Feature004DependenciesTest extends TestCase
{
    use MailboxTestHelpers;

    private function admin(): Principal
    {
        return new Principal('admin', Role::Admin, AccountKind::Admin, AuthMethod::Password);
    }

    public function test_domain_names_are_normalised_and_validated_like_legacy(): void
    {
        $this->assertSame('xn--bcher-kva.example', DomainName::normalize(' Bücher.Example '));
        $this->assertSame('bücher.example', DomainName::toUnicode('xn--bcher-kva.example'));
        $this->assertTrue(DomainName::isValid('localdomain'));
        $this->assertTrue(DomainName::isValid(str_repeat('a', 63).'.example'));
        $this->assertFalse(DomainName::isValid(str_repeat('a', 64).'.example'));
        $this->assertFalse(DomainName::isValid(str_repeat('abcdefghi.', 26).'example'));
        $this->assertFalse(DomainName::isValid('exa_mple.org'));
    }

    public function test_the_alias_figure_counts_alias_domains_and_not_own_alias_rows(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->aliasDomain('example.net', 'example.org');
        $f->legacyMailbox('anna@example.org');
        $f->alias('sales@example.org', 'anna@example.org');
        $f->alias('info@example.net', 'anna@example.org');

        $this->assertSame(2, $this->app->make(DomainUsageCalculator::class)->forDomain('example.org')?->aliases);
    }

    public function test_address_availability_names_the_holder_case_insensitively(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->legacyMailbox('anna@example.org');
        $f->resource('Room1@example.org', 'Room 1');
        $f->alias('sales@example.org', 'anna@example.org');
        $f->temporaryAlias('spam123@example.org', 'anna@example.org');
        $availability = $this->app->make(AddressAvailability::class);

        $this->assertSame('mailbox', $availability->holderOf('ANNA@example.org'));
        $this->assertSame('resource', $availability->holderOf('room1@example.org'));
        $this->assertSame('alias', $availability->holderOf('Sales@example.org'));
        $this->assertSame('temporaryAlias', $availability->holderOf('spam123@example.org'));
        $this->assertNull($availability->holderOf('free@example.org'));
        $this->assertSame('restv2_address_'.sha1('sales@example.org'), AddressLock::name('Sales@Example.org'));
    }

    public function test_the_shared_store_writes_and_compensates_domain_map_and_rate_limit_fields(): void
    {
        $store = $this->app->make(SharedStore::class);

        $undo = $store->setFields('DOMAIN_MAP', ['example.net' => '1']);
        $this->assertSame('1', $this->redis()->hGet('DOMAIN_MAP', 'example.net'));
        $undo();
        $this->assertFalse($this->redis()->hGet('DOMAIN_MAP', 'example.net'));

        $this->app->make(RateLimitStore::class)->set('example.net', 500, 'hour');
        $this->assertSame('500 / 1h', $this->redis()->hGet('RL_VALUE', 'example.net'));
        $restore = $store->deleteFields(['RL_VALUE' => ['example.net']]);
        $this->assertFalse($this->redis()->hGet('RL_VALUE', 'example.net'));
        $restore();
        $this->assertSame('500 / 1h', $this->redis()->hGet('RL_VALUE', 'example.net'));

        $this->breakSharedStore();
        $this->expectException(SharedStoreUnavailable::class);
        $this->app->make(SharedStore::class)->ping();
    }

    public function test_dkim_keys_of_new_names_are_written_through_the_dkim_service(): void
    {
        $step = $this->app->make(DkimKeyService::class)->generateForNewName('example.net', 'dkim', 1024, new AuditContext($this->admin(), 'aliasDomainCreation'));

        $this->assertSame(DkimCreationStep::CREATED, $step->outcome);
        $this->assertSame(DkimDnsOutcome::NotConfigured, $step->publication?->outcome);
        $this->assertSame('dkim', $this->redis()->hGet('DKIM_SELECTORS', 'example.net'));
        $this->assertNotFalse($this->redis()->hGet('DKIM_PUB_KEYS', 'example.net'));
        $this->assertNotFalse($this->redis()->hGet('DKIM_PRIV_KEYS', 'dkim.example.net'));

        $again = $this->app->make(DkimKeyService::class)->generateForNewName('example.net', 'other', 1024, new AuditContext($this->admin(), 'aliasDomainCreation'));
        $this->assertSame(DkimCreationStep::ALREADY_EXISTED, $again->outcome);
        $this->assertNull($again->publication);
    }

    public function test_the_groupware_directory_refreshes_rebuilds_and_respects_skip_sogo(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->legacyMailbox('anna@example.org');
        $directory = $this->app->make(SogoDirectory::class);

        $this->assertSame(GroupwareOutcome::Done, $directory->refresh(['anna@example.org']));
        $this->assertSame(1, DB::table('_sogo_static_view')->where('c_uid', 'anna@example.org')->count());
        $this->assertSame(0, $this->memcached()->flushCount());
        $this->assertSame(GroupwareOutcome::Done, $directory->rebuild());
        $this->assertSame(1, $this->memcached()->flushCount());

        DB::table('_sogo_static_view')->delete();
        config(['restv2.skip_sogo' => true]);
        $this->assertSame(GroupwareOutcome::GroupwareDisabled, $directory->refresh(['anna@example.org']));
        $this->assertSame(GroupwareOutcome::GroupwareDisabled, $directory->rebuild());
        $this->assertSame(0, DB::table('_sogo_static_view')->count());
    }

    public function test_the_shared_alias_domain_cleanup_removes_the_rows_and_names_the_redis_fields(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->aliasDomain('example.net', 'example.org');
        $f->legacyMailbox('anna@example.org');
        $f->alias('info@example.net', 'anna@example.org');
        $f->senderAcl('anna@example.org', 'info@example.net');
        $f->senderAcl('anna@example.org', '@example.net');
        $f->spamalias('tmp@example.net', 'anna@example.org');
        $f->bccMap('@example.net', 'archive@example.org', 'example.net');
        $f->filterconf('example.net', 'whitelist_from', 'friend@example.com');
        $cleanup = $this->app->make(AliasDomainCleanup::class);

        DB::transaction(fn () => $cleanup->deleteRows('example.net'));

        $this->assertSame(0, DB::table('alias_domain')->count());
        $this->assertSame(0, DB::table('alias')->where('domain', 'example.net')->count());
        $this->assertSame(0, DB::table('sender_acl')->count());
        $this->assertSame(0, DB::table('spamalias')->count());
        $this->assertSame(0, DB::table('bcc_maps')->count());
        $this->assertSame(0, DB::table('filterconf')->count());
        $this->assertSame(['DOMAIN_MAP' => ['example.net'], 'RL_VALUE' => ['example.net']], $cleanup->redisFields('example.net'));
    }

    public function test_a_pending_follow_up_is_completed_once(): void
    {
        $logger = $this->app->make(AuditLogger::class);
        $event = $logger->record(AuditEventType::AliasDomainCreation, true, $this->admin(), ['type' => 'aliasDomain', 'id' => 'example.net', 'name' => 'example.net'], null, null, ['creationReport' => 'pending']);

        $logger->completeFollowUps($event->id, 'creationReport', ['rateLimit' => 'notRequested']);

        $details = json_decode((string) DB::table('restv2_audit_events')->where('id', $event->id)->value('details'), true);
        $this->assertSame(['rateLimit' => 'notRequested'], $details['creationReport']);
        $this->expectException(\LogicException::class);
        $logger->completeFollowUps($event->id, 'creationReport', ['rateLimit' => 'applied']);
    }

    public function test_refusals_on_routes_of_this_feature_are_recorded_with_their_targets(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $id = $f->alias('sales@example.org', 'anna@example.org');
        $f->resource('Room1@example.org', 'Room 1');
        $f->aliasDomain('example.net', 'example.org');
        $f->admin('admin', 'Passw0rd!');
        $refuse = fn () => throw new ProblemException(ProblemType::Forbidden);

        foreach ([
            ['/api/v2/__testing/alias/{aliasId}', 'aliasUpdate', 'alias'],
            ['/api/v2/__testing/alias-domain/{aliasDomain}', 'aliasDomainUpdate', 'aliasDomain'],
            ['/api/v2/__testing/resource/{resourceAddress}', 'resourceUpdate', 'resource'],
            ['/api/v2/__testing/sender-settings/{mailbox}', 'mailboxSenderSettingsUpdate', 'mailboxSenderSettings'],
        ] as [$path, $event, $target]) {
            Route::middleware(['api.v2', 'restv2.auth:authenticated', 'restv2.csrf', 'restv2.pending'])
                ->post($path, $refuse)
                ->defaults('restv2.audit', ['event' => $event, 'target' => $target]);
        }

        $this->signInAs('admin', Role::Admin);
        $this->postJson("/api/v2/__testing/alias/{$id}", [], $this->csrf())->assertStatus(403);
        $this->postJson('/api/v2/__testing/alias-domain/EXAMPLE.net', [], $this->csrf())->assertStatus(403);
        $this->postJson('/api/v2/__testing/resource/room1@example.org', [], $this->csrf())->assertStatus(403);
        $this->postJson('/api/v2/__testing/sender-settings/Anna@Example.org', [], $this->csrf())->assertStatus(403);
        $this->postJson('/api/v2/__testing/alias/999999', [], $this->csrf())->assertStatus(403);

        $alias = $this->auditOf('aliasUpdate');
        $this->assertCount(2, $alias);
        $this->assertSame(['alias', (string) $id, 'sales@example.org'], [$alias[0]->target_type, $alias[0]->target_id, $alias[0]->target_name]);
        $this->assertSame(['alias', '999999', null], [$alias[1]->target_type, $alias[1]->target_id, $alias[1]->target_name]);
        $this->assertSame('example.net', $this->auditOf('aliasDomainUpdate')[0]->target_id);
        $this->assertSame(['Room1@example.org', 'Room 1'], [$this->auditOf('resourceUpdate')[0]->target_id, $this->auditOf('resourceUpdate')[0]->target_name]);
        $this->assertSame('anna@example.org', $this->auditOf('mailboxSenderSettingsUpdate')[0]->target_id);
        $this->assertSame('permissionDenied', $alias[0]->failure_reason);
    }
}
