<?php

namespace Tests\Feature\Routing\RelayhostAssignments;

use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T038 (FR-024, FR-026–FR-030): getDomainRelayhostAssignment and
 * replaceDomainRelayhostAssignment.
 */
class DomainRelayhostAssignmentTest extends TestCase
{
    use RoutingTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->relayhost(3, '[smtp.example.net]:587', 'acct', 's3cret');
        $f->relayhost(4, 'inactive.example', '', '', 0);
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->domain('xn--bcher-kva.example');
        $f->aliasDomain('example.net', 'example.org');
    }

    private const URI = '/api/v2/domains/example.org/relayhost-assignment';

    public function test_reads_none_active_inactive_and_dangling_references(): void
    {
        $this->asAdmin();

        $this->getJson(self::URI)->assertOk()->assertJsonPath('data.domain', 'example.org')->assertJsonPath('data.relayhost', null);

        $this->mailcow()->domainRelayhost('example.org', '3');
        $this->getJson(self::URI)->assertJsonPath('data.relayhost', ['id' => 3, 'host' => '[smtp.example.net]:587', 'state' => 'active']);

        $this->mailcow()->domainRelayhost('example.org', '4');
        $this->getJson(self::URI)->assertJsonPath('data.relayhost', ['id' => 4, 'host' => 'inactive.example', 'state' => 'inactive']);

        $this->mailcow()->domainRelayhost('example.org', '77');
        $response = $this->getJson(self::URI)->assertJsonPath('data.relayhost', ['id' => 77, 'host' => null, 'state' => 'dangling']);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
    }

    public function test_domain_names_are_normalised_and_alias_domains_are_not_found(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/domains/EXAMPLE.org/relayhost-assignment')->assertOk()->assertJsonPath('data.domain', 'example.org');
        $this->getJson('/api/v2/domains/B%C3%BCcher.example/relayhost-assignment')->assertOk()->assertJsonPath('data.domain', 'xn--bcher-kva.example');
        $this->getJson('/api/v2/domains/example.net/relayhost-assignment')->assertNotFound();
        $this->getJson('/api/v2/domains/unknown.example/relayhost-assignment')->assertNotFound();
    }

    public function test_an_administrator_assigns_a_relayhost_and_only_the_assignment_changes(): void
    {
        $this->asAdmin();
        $domainEtag = $this->getJson('/api/v2/domains/example.org')->headers->get('ETag');
        $before = (array) $this->domainRow('example.org');
        $etag = (string) $this->getJson(self::URI)->headers->get('ETag');

        $response = $this->send('PUT', self::URI, ['relayhostId' => 3], ['If-Match' => $etag])->assertOk()
            ->assertJsonPath('data.relayhost', ['id' => 3, 'host' => '[smtp.example.net]:587', 'state' => 'active']);
        $this->assertNotSame($etag, $response->headers->get('ETag'));

        $after = (array) $this->domainRow('example.org');
        $this->assertSame('3', $after['relayhost']);
        $this->assertSame(array_diff_key($before, ['relayhost' => 1, 'modified' => 1]), array_diff_key($after, ['relayhost' => 1, 'modified' => 1]));
        $this->getJson('/api/v2/domains/example.org')->assertJsonPath('data.relayhost.id', 3);
        $this->assertNotSame($domainEtag, $this->getJson('/api/v2/domains/example.org')->headers->get('ETag'));

        [$record] = $this->auditRecords('relayhostAssignmentUpdate', 'domainRelayhostAssignment', 'example.org');
        $this->assertSame(['changes' => ['relayhostId' => ['old' => null, 'new' => 3]]], $record->details);
        $this->assertSame([], $this->dockerApi()->calls());
        $this->assertSame(0, $this->memcached()->flushCount());
    }

    public function test_a_domain_administrator_with_the_flag_assigns_to_an_own_domain(): void
    {
        $this->asRoutingDomainAdmin(['example.org'], ['domain_relayhost' => 1]);

        $this->send('PUT', self::URI, ['relayhostId' => 3])->assertOk();

        $this->assertSame('3', $this->domainRow('example.org')?->relayhost);
    }

    public function test_a_domain_administrator_without_the_flag_is_refused_and_audited(): void
    {
        foreach ([[], ['mailbox_relayhost' => 1]] as $i => $flags) {
            $this->asRoutingDomainAdmin(['example.org'], $flags, 'da'.$i);

            $this->getJson(self::URI)->assertOk();
            $this->send('PUT', self::URI, ['relayhostId' => 3])->assertForbidden()
                ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
                ->assertJsonPath('requiredFlags', ['domainRelayhost']);
        }

        $this->assertSame('0', $this->domainRow('example.org')?->relayhost);
        $records = $this->auditRecords('relayhostAssignmentUpdate', 'domainRelayhostAssignment', 'example.org');
        $this->assertCount(2, $records);
        $this->assertSame('permissionDenied', $records[0]->failure_reason);
        $this->assertSame(['domainRelayhost'], $records[0]->details['requiredFlags']);
    }

    public function test_an_unknown_relayhost_is_refused_and_an_inactive_one_accepted(): void
    {
        $this->asAdmin();

        $this->send('PUT', self::URI, ['relayhostId' => 999])->assertStatus(422)->assertJsonPath('errors.relayhostId.0.code', 'notFound');
        $this->send('PUT', self::URI, ['relayhostId' => 4])->assertOk()->assertJsonPath('data.relayhost.state', 'inactive');
    }

    public function test_foreign_domains_are_not_found_for_a_domain_administrator(): void
    {
        $this->asRoutingDomainAdmin(['example.org'], ['domain_relayhost' => 1]);

        $foreign = $this->getJson('/api/v2/domains/foreign.example/relayhost-assignment')->assertNotFound();
        $unknown = $this->getJson('/api/v2/domains/unknown.example/relayhost-assignment')->assertNotFound();
        $this->assertSame($foreign->json('title'), $unknown->json('title'));
        $this->send('PUT', '/api/v2/domains/foreign.example/relayhost-assignment', ['relayhostId' => 3])->assertNotFound();
        $this->assertSame('0', $this->domainRow('foreign.example')?->relayhost);
    }

    public function test_an_assignment_and_a_dangling_one_can_be_cleared(): void
    {
        $this->asAdmin();
        $this->mailcow()->domainRelayhost('example.org', '77');

        $this->send('PUT', self::URI, ['relayhostId' => null])->assertOk()->assertJsonPath('data.relayhost', null);
        $this->assertSame('0', $this->domainRow('example.org')?->relayhost);

        $this->mailcow()->domainRelayhost('example.org', '77');
        $this->send('PUT', self::URI, ['relayhostId' => 3])->assertOk()->assertJsonPath('data.relayhost.id', 3);
        [, $second] = $this->auditRecords('relayhostAssignmentUpdate', 'domainRelayhostAssignment', 'example.org');
        $this->assertSame(['old' => 77, 'new' => 3], $second->details['changes']['relayhostId']);
    }

    public function test_a_stale_version_is_refused(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson(self::URI)->headers->get('ETag');
        $this->send('PUT', self::URI, ['relayhostId' => 3], ['If-Match' => $etag])->assertOk();

        $this->send('PUT', self::URI, ['relayhostId' => null], ['If-Match' => $etag])->assertStatus(412);
        $this->assertSame('3', $this->domainRow('example.org')?->relayhost);
    }

    public function test_invalid_bodies_are_refused(): void
    {
        $this->asAdmin();
        $this->withoutRequestValidation();

        $this->send('PUT', self::URI, [])->assertStatus(422);
        $this->send('PUT', self::URI, ['relayhostId' => '3'])->assertStatus(422)->assertJsonPath('errors.relayhostId.0.code', 'invalidType');
        $this->send('PUT', self::URI, ['relayhostId' => 0])->assertStatus(422)->assertJsonPath('errors.relayhostId.0.code', 'outOfRange');
    }
}
