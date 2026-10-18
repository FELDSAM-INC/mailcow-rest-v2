<?php

namespace Tests\Feature\Routing\RelayhostAssignments;

use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T039 (FR-024–FR-030, edge cases): getMailboxRelayhostAssignment and
 * replaceMailboxRelayhostAssignment, with the domain's assignment, the effective relayhost and the
 * relayhost whose credentials the mail server sends.
 */
class MailboxRelayhostAssignmentTest extends TestCase
{
    use RoutingTestHelpers;

    private const URI = '/api/v2/mailboxes/user@example.org/relayhost-assignment';

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->relayhost(3, 'domain-relay.example', 'dom', 'pw3');
        $f->relayhost(5, 'mailbox-relay.example', 'box', 'pw5');
        $f->relayhost(6, 'no-login.example');
        $f->relayhost(7, 'inactive.example', 'x', 'y', 0);
        $f->domain('example.org', attributes: ['relayhost' => '3']);
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $this->mailbox('user@example.org');
        $this->mailbox('other@foreign.example');
        $f->resource('room@example.org', 'Room');
    }

    public function test_a_domain_administrator_with_the_flag_assigns_a_mailbox_relayhost(): void
    {
        $this->asRoutingDomainAdmin(['example.org'], ['mailbox_relayhost' => 1]);
        $before = $this->mailboxAttributes('user@example.org');
        $mailboxEtag = $this->getJson('/api/v2/mailboxes/user@example.org')->headers->get('ETag');

        $this->send('PUT', self::URI, ['relayhostId' => 5])->assertOk()
            ->assertJsonPath('data.mailbox', 'user@example.org')
            ->assertJsonPath('data.domain', 'example.org')
            ->assertJsonPath('data.relayhost', ['id' => 5, 'host' => 'mailbox-relay.example', 'state' => 'active'])
            ->assertJsonPath('data.domainRelayhost', ['id' => 3, 'host' => 'domain-relay.example', 'state' => 'active'])
            ->assertJsonPath('data.effectiveRelayhost', ['id' => 5, 'host' => 'mailbox-relay.example', 'source' => 'mailbox'])
            ->assertJsonPath('data.credentialsRelayhost', ['id' => 3, 'source' => 'domain']);

        $after = $this->mailboxAttributes('user@example.org');
        $this->assertSame('5', $after['relayhost']);
        $this->assertSame(array_diff_key($before, ['relayhost' => 1]), array_diff_key($after, ['relayhost' => 1]));
        $this->assertNotSame($mailboxEtag, $this->getJson('/api/v2/mailboxes/user@example.org')->headers->get('ETag'));

        [$record] = $this->auditRecords('relayhostAssignmentUpdate', 'mailboxRelayhostAssignment', 'user@example.org');
        $this->assertSame(['old' => null, 'new' => 5], $record->details['changes']['relayhostId']);
    }

    public function test_the_effective_and_credentials_relayhosts(): void
    {
        $this->asAdmin();
        $f = $this->mailcow();

        $f->mailboxRelayhost('user@example.org', '7');
        $this->getJson(self::URI)->assertOk()
            ->assertJsonPath('data.relayhost.state', 'inactive')
            ->assertJsonPath('data.effectiveRelayhost', ['id' => 3, 'host' => 'domain-relay.example', 'source' => 'domain'])
            ->assertJsonPath('data.credentialsRelayhost', ['id' => 3, 'source' => 'domain']);

        $f->domainRelayhost('example.org', '6');
        $f->mailboxRelayhost('user@example.org', '5');
        $this->getJson(self::URI)
            ->assertJsonPath('data.effectiveRelayhost.id', 5)
            ->assertJsonPath('data.credentialsRelayhost', null);

        $f->domainRelayhost('example.org', '0');
        $this->getJson(self::URI)->assertJsonPath('data.credentialsRelayhost', ['id' => 5, 'source' => 'mailbox']);

        $f->mailboxRelayhost('user@example.org', '0');
        $this->getJson(self::URI)
            ->assertJsonPath('data.relayhost', null)
            ->assertJsonPath('data.domainRelayhost', null)
            ->assertJsonPath('data.effectiveRelayhost', null)
            ->assertJsonPath('data.credentialsRelayhost', null);

        $f->mailboxRelayhost('user@example.org', null);
        $response = $this->getJson(self::URI)->assertJsonPath('data.relayhost', null);
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
    }

    public function test_resources_alias_domain_addresses_and_foreign_mailboxes_are_not_found(): void
    {
        $this->asRoutingDomainAdmin(['example.org'], ['mailbox_relayhost' => 1]);

        $this->getJson('/api/v2/mailboxes/room@example.org/relayhost-assignment')->assertNotFound();
        $this->getJson('/api/v2/mailboxes/user@example.net/relayhost-assignment')->assertNotFound();
        $this->getJson('/api/v2/mailboxes/other@foreign.example/relayhost-assignment')->assertNotFound();
        $this->send('PUT', '/api/v2/mailboxes/other@foreign.example/relayhost-assignment', ['relayhostId' => 5])->assertNotFound();
        $this->getJson('/api/v2/mailboxes/USER@Example.ORG/relayhost-assignment')->assertOk()->assertJsonPath('data.mailbox', 'user@example.org');
    }

    public function test_a_domain_administrator_without_the_flag_is_refused(): void
    {
        $this->asRoutingDomainAdmin(['example.org'], ['domain_relayhost' => 1]);

        $this->getJson(self::URI)->assertOk();
        $this->send('PUT', self::URI, ['relayhostId' => 5])->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['mailboxRelayhost']);
        $this->send('PUT', '/api/v2/mailboxes/other@foreign.example/relayhost-assignment', ['relayhostId' => 5])->assertForbidden();
        $this->assertSame('0', $this->mailboxAttributes('user@example.org')['relayhost']);
    }

    public function test_clearing_and_a_stale_version(): void
    {
        $this->asAdmin();
        $etag = (string) $this->getJson(self::URI)->headers->get('ETag');
        $this->send('PUT', self::URI, ['relayhostId' => 5], ['If-Match' => $etag])->assertOk();
        $this->send('PUT', self::URI, ['relayhostId' => null], ['If-Match' => $etag])->assertStatus(412);
        $this->assertSame('5', $this->mailboxAttributes('user@example.org')['relayhost']);

        $this->send('PUT', self::URI, ['relayhostId' => null])->assertOk()->assertJsonPath('data.relayhost', null);
        $this->assertSame('0', $this->mailboxAttributes('user@example.org')['relayhost']);
        $this->send('PUT', self::URI, ['relayhostId' => 999])->assertStatus(422)->assertJsonPath('errors.relayhostId.0.code', 'notFound');
    }

    public function test_a_mailbox_user_is_refused(): void
    {
        $this->signInAsMailboxUser('user@example.org');

        $this->getJson(self::URI)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->getJson('/api/v2/domains/example.org/relayhost-assignment')->assertForbidden();
    }
}
