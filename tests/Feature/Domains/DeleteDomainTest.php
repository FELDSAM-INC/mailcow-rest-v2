<?php

namespace Tests\Feature\Domains;

use App\Clients\DockerApi\DockerCallResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 002 T067 (FR-035, FR-036, FR-039, FR-040, FR-056, US4): deleteDomain.
 */
class DeleteDomainTest extends TestCase
{
    use ApiKeys;
    use DomainTestHelpers;

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function deleteDomain(string $domain = 'example.org', array $headers = []): TestResponse
    {
        return $this->deleteJson('/api/v2/domains/'.$domain, [], $this->csrf() + $headers);
    }

    public function test_an_empty_domain_is_deleted_with_its_aliases_and_alias_domains(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->alias('a@example.org', 'x@external.example');
        $f->alias('b@example.org', 'x@external.example');
        $f->aliasDomain('alias.example', 'example.org');
        $f->alias('c@alias.example', 'x@external.example');
        $this->redis()->hSet('DOMAIN_MAP', 'example.org', '1');
        $this->redis()->hSet('DOMAIN_MAP', 'alias.example', '1');
        $this->asAdmin();

        $response = $this->deleteDomain()->assertNoContent();

        $this->assertSame('', (string) $response->getContent());
        $this->assertNull($this->domainRow('example.org'));
        $this->assertSame(0, DB::table('alias')->count());
        $this->assertSame(0, DB::table('alias_domain')->count());
        $this->assertSame([], $this->redis()->hGetAll('DOMAIN_MAP'));
        $this->dockerApi()->assertExecuted('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => 'example.org']);
        $this->assertSame(1, $this->memcached()->flushCount());
    }

    public function test_a_domain_with_a_mailbox_or_a_resource_is_not_empty(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->mailbox('user@a.example');
        $this->mailcow()->domain('b.example');
        $this->mailcow()->mailbox('room@b.example', kind: 'location');
        $this->asAdmin();

        $this->deleteDomain('a.example')->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/domain-not-empty')
            ->assertJsonPath('mailboxes', 1)
            ->assertJsonPath('resources', 0);
        $this->deleteDomain('b.example')->assertStatus(409)->assertJsonPath('mailboxes', 0)->assertJsonPath('resources', 1);

        $this->assertNotNull($this->domainRow('a.example'));
        $this->assertNotNull($this->domainRow('b.example'));
        $this->dockerApi()->assertNothingCalled();
    }

    public function test_unknown_names_alias_domains_and_other_roles(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->aliasDomain('alias.example', 'example.org');
        $this->asAdmin();

        $this->deleteDomain('missing.example')->assertNotFound();
        $this->deleteDomain('alias.example')->assertNotFound();

        $this->forgetCookies();
        $this->asDomainAdmin(['example.org']);
        $this->deleteDomain()->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $key = $this->apiKey('admin', 'readOnly');
        $this->deleteJson('/api/v2/domains/example.org', [], $this->keyHeader($key))->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertNotNull($this->domainRow('example.org'));
    }

    public function test_a_stale_version_and_an_unavailable_store_remove_nothing(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
        $etag = (string) $this->getJson('/api/v2/domains/example.org')->headers->get('ETag');
        DB::table('domain')->where('domain', 'example.org')->update(['mailboxes' => 3]);

        $this->deleteDomain('example.org', ['If-Match' => $etag])->assertStatus(412);
        $this->assertNotNull($this->domainRow('example.org'));

        $csrf = $this->csrf();
        config(['database.redis.mailcow.port' => 1]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstances();
        $this->deleteJson('/api/v2/domains/example.org', [], $csrf)->assertStatus(503)->assertHeader('Retry-After', '10');
        $this->assertNotNull($this->domainRow('example.org'));
    }

    public function test_a_failed_or_unknown_mail_move_does_not_stop_the_deletion(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->domain('b.example');
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->asAdmin();

        $this->dockerApi()->respondTo('exec', DockerCallResult::failed('no such directory'));
        $this->deleteDomain('a.example')->assertNoContent();
        $this->dockerApi()->respondTo('exec', DockerCallResult::unknown('timed out'));
        $this->deleteDomain('b.example')->assertNoContent();

        $records = $this->auditOf('domainDeletion');
        $this->assertSame(['outcome' => 'failed', 'message' => 'no such directory'], $records[0]->details['maildirCleanup']);
        $this->assertSame('unknown', $records[1]->details['maildirCleanup']['outcome']);
        $this->assertSame(0, DB::table('domain')->count());
    }
}
