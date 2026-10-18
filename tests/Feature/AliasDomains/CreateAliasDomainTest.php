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
 * Feature 004 T048 (FR-040–FR-043, FR-062, US4): createAliasDomain with its follow-up steps.
 */
class CreateAliasDomainTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $this->mailbox('anna@example.org');
    }

    public function test_a_domain_administrator_with_the_flag_creates_an_alias_domain(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['alias_domains' => 1]);

        $response = $this->postJson('/api/v2/alias-domains', ['name' => 'Example.NET', 'targetDomain' => 'example.org', 'dkimKeySize' => 1024], $this->csrf())->assertCreated();

        $response->assertHeader('Location', '/api/v2/alias-domains/example.net');
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertSame(['rateLimit' => 'notRequested', 'dkimKey' => 'created', 'dkimDnsPublication' => 'notConfigured', 'groupwareDirectoryUpdate' => 'done'], $response->json('data.creationReport'));
        $this->assertSame(1, DB::table('alias_domain')->where('alias_domain', 'example.net')->where('target_domain', 'example.org')->where('active', 1)->count());
        $this->assertSame('1', $this->redis()->hGet('DOMAIN_MAP', 'example.net'));
        $this->assertSame('dkim', $this->redis()->hGet('DKIM_SELECTORS', 'example.net'));
        $this->assertNotFalse($this->redis()->hGet('DKIM_PRIV_KEYS', 'dkim.example.net'));
        $this->assertStringContainsString('anna@example.net', (string) $this->viewRow('anna@example.org')?->ad_aliases);
        $this->assertSame(1, $this->memcached()->flushCount());

        $record = $this->auditOf('aliasDomainCreation')[0];
        $this->assertSame(['aliasDomain', 'example.net', 'example.net'], [$record->target_type, $record->target_id, $record->target_name]);
        $this->assertSame($response->json('data.creationReport'), $record->details['creationReport']);
        $this->assertSame('example.org', $record->details['values']['targetDomain']);
        $this->assertSame('aliasDomainCreation', $this->auditOf('dkimKeyGeneration')[0]->details['trigger']);
    }

    public function test_the_alias_domains_flag_is_checked_before_an_invalid_body(): void
    {
        $this->withoutRequestValidation();
        $this->asDomainAdmin(['example.org'], 'reseller', ['alias_domains' => 0]);

        foreach ([['name' => 'example.net', 'targetDomain' => 'example.org'], ['name' => 42]] as $body) {
            $this->postJson('/api/v2/alias-domains', $body, $this->csrf())->assertForbidden()
                ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
                ->assertJsonPath('requiredFlags', ['aliasDomains']);
        }

        $this->assertSame(0, DB::table('alias_domain')->count());
        $this->assertSame(['permissionDenied', 'permissionDenied'], array_column($this->auditOf('aliasDomainCreation'), 'failure_reason'));
    }

    public function test_an_initial_rate_limit_needs_the_ratelimit_flag(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['alias_domains' => 1, 'ratelimit' => 0]);

        $this->postJson('/api/v2/alias-domains', ['name' => 'example.net', 'targetDomain' => 'example.org', 'rateLimit' => ['value' => 500, 'frame' => 'hour']], $this->csrf())
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/attribute-not-permitted')
            ->assertJsonPath('attributes', ['rateLimit'])
            ->assertJsonPath('requiredFlags', ['rateLimit' => 'ratelimit']);
        $this->assertSame(0, DB::table('alias_domain')->count());
        $this->assertFalse($this->redis()->hGet('DOMAIN_MAP', 'example.net'));

        DB::table('da_acl')->where('username', 'reseller')->update(['ratelimit' => 1]);
        $this->postJson('/api/v2/alias-domains', ['name' => 'example.net', 'targetDomain' => 'example.org', 'rateLimit' => ['value' => 500, 'frame' => 'hour'], 'dkimSelector' => ''], $this->csrf())
            ->assertCreated()->assertJsonPath('data.creationReport.rateLimit', 'applied')->assertJsonPath('data.rateLimit', ['value' => 500, 'frame' => 'hour']);
        $this->assertSame('500 / 1h', $this->redis()->hGet('RL_VALUE', 'example.net'));
    }

    public function test_existing_names_are_conflicts(): void
    {
        $this->mailcow()->aliasDomain('example.net', 'example.org');
        $this->asAdmin();

        $this->postJson('/api/v2/alias-domains', ['name' => 'foreign.example', 'targetDomain' => 'example.org'], $this->csrf())->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/alias-domain-already-exists')
            ->assertJsonPath('name', 'foreign.example')
            ->assertJsonPath('existingKind', 'domain');
        $this->postJson('/api/v2/alias-domains', ['name' => 'EXAMPLE.net', 'targetDomain' => 'example.org'], $this->csrf())->assertStatus(409)
            ->assertJsonPath('existingKind', 'aliasDomain');
        $this->assertSame('conflict', $this->auditOf('aliasDomainCreation')[0]->failure_reason);
    }

    public function test_invalid_targets_and_names(): void
    {
        $f = $this->mailcow();
        $f->domain('relay.example', attributes: ['backupmx' => 1]);
        $f->aliasDomain('example.net', 'example.org');
        $this->asDomainAdmin(['example.org', 'relay.example'], 'reseller', ['alias_domains' => 1]);

        $cases = [
            [['name' => 'a.example', 'targetDomain' => 'relay.example'], 'targetDomain', 'notEligible', ['kind' => 'relayDomain']],
            [['name' => 'a.example', 'targetDomain' => 'example.net'], 'targetDomain', 'notEligible', ['kind' => 'aliasDomain']],
            [['name' => 'a.example', 'targetDomain' => 'foreign.example'], 'targetDomain', 'notFound', ['resource' => 'domain']],
            [['name' => 'a.example', 'targetDomain' => 'unknown.example'], 'targetDomain', 'notFound', ['resource' => 'domain']],
            [['name' => 'mail.example.org', 'targetDomain' => 'example.org'], 'name', 'reserved', []],
            [['name' => 'example.org', 'targetDomain' => 'example.org'], 'name', 'conflictsWith', ['field' => 'targetDomain', 'value' => 'example.org']],
            [['name' => 'exa_mple.net', 'targetDomain' => 'example.org'], 'name', 'invalidFormat', ['format' => 'domainName']],
        ];

        foreach ($cases as [$body, $field, $code, $params]) {
            $errors = $this->postJson('/api/v2/alias-domains', $body, $this->csrf())->assertStatus(422)->json('errors');
            $this->assertSame($code, $errors[$field][0]['code'], json_encode($body));
            $this->assertEquals($params, $errors[$field][0]['params']);
        }

        $this->assertSame(1, DB::table('alias_domain')->count());
    }

    public function test_external_sender_addresses_in_the_new_alias_domain_are_removed(): void
    {
        $f = $this->mailcow();
        $f->senderAcl('anna@example.org', 'bob@example.net', 1);
        $f->senderAcl('anna@example.org', '@example.net', 1);
        $f->senderAcl('anna@example.org', 'bob@other.example', 1);
        $f->senderAcl('anna@example.org', 'bob@example.net');
        $this->asAdmin();

        $this->postJson('/api/v2/alias-domains', ['name' => 'example.net', 'targetDomain' => 'example.org', 'dkimSelector' => ''], $this->csrf())->assertCreated();

        $this->assertSame(['anna@example.org bob@example.net', 'anna@example.org bob@other.example external'], $this->senderAcls());
        $this->assertSame(2, $this->auditOf('aliasDomainCreation')[0]->details['values']['removedExternalSenderAddresses']);
    }

    public function test_dkim_options(): void
    {
        $this->mailcow()->redisHash('DKIM_PUB_KEYS', 'kept.example', 'MIIB');
        $this->mailcow()->redisHash('DKIM_SELECTORS', 'kept.example', 'old');
        $this->asAdmin();

        $this->postJson('/api/v2/alias-domains', ['name' => 'kept.example', 'targetDomain' => 'example.org'], $this->csrf())->assertCreated()
            ->assertJsonPath('data.creationReport.dkimKey', 'alreadyExisted')->assertJsonPath('data.creationReport.dkimDnsPublication', 'skipped');
        $this->assertSame('old', $this->redis()->hGet('DKIM_SELECTORS', 'kept.example'));

        $this->postJson('/api/v2/alias-domains', ['name' => 'none.example', 'targetDomain' => 'example.org', 'dkimSelector' => ''], $this->csrf())->assertCreated()
            ->assertJsonPath('data.creationReport.dkimKey', 'notRequested');
        $this->assertFalse($this->redis()->hGet('DKIM_SELECTORS', 'none.example'));

        $this->postJson('/api/v2/alias-domains', ['name' => 'custom.example', 'targetDomain' => 'example.org', 'dkimSelector' => 'feldhost', 'dkimKeySize' => 1024], $this->csrf())->assertCreated()
            ->assertJsonPath('data.creationReport.dkimKey', 'created');
        $this->assertSame('feldhost', $this->redis()->hGet('DKIM_SELECTORS', 'custom.example'));
    }

    public function test_the_dkim_dns_publication_is_reported(): void
    {
        config([
            'restv2.dkim.dns_publication' => true,
            'restv2.ispconfig.url' => 'https://ispconfig.example/remote/json.php',
            'restv2.ispconfig.username' => 'remote',
            'restv2.ispconfig.password' => 'secret',
        ]);
        $this->ispconfig()->zone('example.net.', 7, 3);
        $this->asAdmin();

        $this->postJson('/api/v2/alias-domains', ['name' => 'example.net', 'targetDomain' => 'example.org', 'dkimKeySize' => 1024], $this->csrf())->assertCreated()
            ->assertJsonPath('data.creationReport.dkimDnsPublication', 'published');
        $this->postJson('/api/v2/alias-domains', ['name' => 'nozone.example', 'targetDomain' => 'example.org', 'dkimKeySize' => 1024], $this->csrf())->assertCreated()
            ->assertJsonPath('data.creationReport.dkimDnsPublication', 'noZone');
    }

    public function test_an_unavailable_shared_store_stores_nothing(): void
    {
        $this->mailcow()->senderAcl('anna@example.org', 'bob@example.net', 1);
        $this->asAdmin();
        $this->breakSharedStore();

        $this->postJson('/api/v2/alias-domains', ['name' => 'example.net', 'targetDomain' => 'example.org'], $this->csrf())->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertHeader('Retry-After', '10');

        $this->assertSame(0, DB::table('alias_domain')->count());
        $this->assertSame(['anna@example.org bob@example.net external'], $this->senderAcls());
        $this->assertSame('temporaryFailure', $this->auditOf('aliasDomainCreation')[0]->failure_reason);
    }

    public function test_a_failed_commit_removes_the_domain_map_entry_again(): void
    {
        $this->app->instance(AuditLogger::class, new class extends AuditLogger
        {
            public function record(AuditEventType $type, bool $success, mixed $actor, ?array $target, ?string $authMethod, ?string $failureReason, array $details): AuditEvent
            {
                if ($type === AuditEventType::AliasDomainCreation && $success) {
                    throw new RuntimeException('audit log unavailable');
                }

                return parent::record($type, $success, $actor, $target, $authMethod, $failureReason, $details);
            }
        });
        $this->asAdmin();

        $this->postJson('/api/v2/alias-domains', ['name' => 'example.net', 'targetDomain' => 'example.org'], $this->csrf())->assertStatus(500);

        $this->assertSame(0, DB::table('alias_domain')->count());
        $this->assertFalse($this->redis()->hGet('DOMAIN_MAP', 'example.net'));
    }
}
