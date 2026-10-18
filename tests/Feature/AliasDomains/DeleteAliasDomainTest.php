<?php

namespace Tests\Feature\AliasDomains;

use App\Audit\AuditEventType;
use App\Models\AuditEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T050 (FR-045, FR-062, SC-008, US4): deleteAliasDomain and the shared cleanup.
 */
class DeleteAliasDomainTest extends TestCase
{
    use AliasTestHelpers;

    private int $first = 0;

    private int $second = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $f->aliasDomain('other.net', 'example.org');
        $this->mailbox('anna@example.org');
        $this->first = $f->alias('one@example.net', 'anna@example.org');
        $this->second = $f->alias('two@example.net', 'anna@example.org');
        $f->senderAcl('anna@example.org', '@example.net');
        $f->senderAcl('bob@example.org', 'one@example.net');
        $f->senderAcl('anna@example.org', 'x@example.net', 1);
        $f->temporaryAlias('tmp1@example.net', 'anna@example.org');
        $f->temporaryAlias('tmp2@example.net', 'anna@example.org');
        $f->bccMap('@example.net', 'archive@example.org', 'example.net');
        $f->bccMap('example.net', 'archive@example.org', 'example.net');
        $f->bccMap('anna@example.net', 'archive@example.org', 'example.net');
        $f->filterconf('example.net', 'whitelist_from', 'friend@example.com');
        $f->filterconf('example.net', 'blacklist_from', 'spam@example.com');
        $f->domainFooter('example.org', aliasDomainExclude: ['example.net', 'other.net']);
        $f->redisHash('DOMAIN_MAP', 'example.net', '1');
        $f->redisHash('RL_VALUE', 'example.net', '10 / 1m');
        $f->redisHash('DKIM_SELECTORS', 'example.net', 'dkim');
        $f->redisHash('DKIM_PUB_KEYS', 'example.net', 'MIIB');
    }

    public function test_everything_of_the_alias_domain_is_removed_but_its_dkim_key(): void
    {
        $this->asAdmin();

        $this->deleteJson('/api/v2/alias-domains/example.net', [], $this->csrf())->assertNoContent();

        $this->assertSame(['other.net'], DB::table('alias_domain')->pluck('alias_domain')->all());
        $this->assertSame(0, DB::table('alias')->where('domain', 'example.net')->count());
        $this->assertSame(['anna@example.org x@example.net external'], $this->senderAcls());
        $this->assertSame(0, DB::table('spamalias')->count());
        $this->assertSame(['anna@example.net'], DB::table('bcc_maps')->pluck('local_dest')->all());
        $this->assertSame(0, DB::table('filterconf')->count());
        $this->assertSame(['other.net'], json_decode((string) DB::table('domain_wide_footer')->where('domain', 'example.org')->value('alias_domain_exclude'), true));
        $this->assertFalse($this->redis()->hGet('DOMAIN_MAP', 'example.net'));
        $this->assertFalse($this->redis()->hGet('RL_VALUE', 'example.net'));
        $this->assertSame('dkim', $this->redis()->hGet('DKIM_SELECTORS', 'example.net'));
        $this->assertSame('MIIB', $this->redis()->hGet('DKIM_PUB_KEYS', 'example.net'));
        $this->assertSame(1, $this->memcached()->flushCount());

        $record = $this->auditOf('aliasDomainDeletion')[0];
        $this->assertSame([
            'aliases' => [['id' => $this->first, 'address' => 'one@example.net'], ['id' => $this->second, 'address' => 'two@example.net']],
            'senderPermissions' => 2,
            'temporaryAliases' => 2,
            'bccMaps' => 2,
            'spamFilterSettings' => 2,
            'footerExclusion' => true,
        ], $record->details['removed']);
    }

    public function test_an_unavailable_shared_store_removes_nothing(): void
    {
        $this->asAdmin();
        $this->breakSharedStore();

        $this->deleteJson('/api/v2/alias-domains/example.net', [], $this->csrf())->assertStatus(503);

        $this->assertSame(2, DB::table('alias_domain')->count());
        $this->assertSame(2, DB::table('alias')->where('domain', 'example.net')->count());
    }

    public function test_a_failed_commit_restores_the_shared_store_entries(): void
    {
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditEventType $type, bool $success, mixed $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): AuditEvent
            {
                if ($type === AuditEventType::AliasDomainDeletion && $success) {
                    throw new RuntimeException('audit log unavailable');
                }

                return parent::record($type, $success, $actor, $target, $authMethod, $failureReason, $details);
            }
        });
        $this->asAdmin();

        $this->deleteJson('/api/v2/alias-domains/example.net', [], $this->csrf())->assertStatus(500);

        $this->assertSame(2, DB::table('alias_domain')->count());
        $this->assertSame('1', $this->redis()->hGet('DOMAIN_MAP', 'example.net'));
        $this->assertSame('10 / 1m', $this->redis()->hGet('RL_VALUE', 'example.net'));
    }

    public function test_versions_and_scope(): void
    {
        $this->asDomainAdmin(['foreign.example']);

        $this->deleteJson('/api/v2/alias-domains/example.net', [], $this->csrf())->assertNotFound();
        $this->assertSame('notFound', $this->auditOf('aliasDomainDeletion')[0]->failure_reason);

        $this->asAdmin();
        $this->deleteJson('/api/v2/alias-domains/example.net', [], ['If-Match' => '"outdated"'] + $this->csrf())->assertStatus(412);
        $this->assertSame(2, DB::table('alias_domain')->count());
    }
}
