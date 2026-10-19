<?php

namespace Tests\Feature\Quarantine;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T045 (FR-002, FR-004, FR-006, AS-2.6): keys act with their owner's role, scope and
 * current flags; read-only keys read and download but never act; domain administrators without
 * `quarantine` read and download attachments only; restricted sessions are refused.
 */
class QuarantineRoleAndKeyAccessTest extends TestCase
{
    use QuarantineTestHelpers;

    private int $mine = 0;

    private int $foreign = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domain('other.example');
        $this->quarantineMailbox('anna@example.org');
        $this->quarantineMailbox('otto@other.example');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['quarantine' => 1], scheme: 'SSHA256');
        $this->mailcow()->domainAdmin('plain', ['example.org'], ['quarantine' => 0], scheme: 'SSHA256');
        $this->mine = $this->item('anna@example.org', 'multipart-two-attachments');
        $this->foreign = $this->item('otto@other.example', 'multipart-two-attachments');
    }

    public function test_a_domain_admins_read_only_key_reads_and_downloads_within_the_scope(): void
    {
        $key = $this->keyHeader($this->apiKey('reseller', 'readOnly'));

        $this->assertSame([$this->mine], array_column((array) $this->getJson('/api/v2/quarantine', $key)->assertOk()->json('data'), 'id'));
        $this->getJson("/api/v2/quarantine/{$this->mine}", $key)->assertOk();
        $this->get("/api/v2/quarantine/{$this->mine}/message", $key)->assertOk();
        $this->get("/api/v2/quarantine/{$this->mine}/attachments/0", $key)->assertOk();
        $this->getJson("/api/v2/quarantine/{$this->foreign}", $key)->assertNotFound();
        $this->send('POST', "/api/v2/quarantine/{$this->mine}/release", null, $key)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->mailer->assertNothingDelivered();
    }

    public function test_a_domain_admin_without_quarantine_reads_but_does_not_act(): void
    {
        $this->signInAs('plain', Role::DomainAdmin);

        $this->getJson('/api/v2/quarantine')->assertOk();
        $this->getJson("/api/v2/quarantine/{$this->mine}")->assertOk()->assertJsonCount(2, 'data.content.attachments');
        $this->get("/api/v2/quarantine/{$this->mine}/attachments/0")->assertOk();
        $this->getJson("/api/v2/quarantine/{$this->mine}/message")->assertForbidden()->assertJsonPath('requiredFlags', ['quarantine']);

        foreach (['release', 'spam-learning'] as $action) {
            $this->send('POST', "/api/v2/quarantine/{$this->mine}/{$action}")->assertForbidden()->assertJsonPath('type', '/api/v2/problems/permission-flag-required');
        }

        $this->send('DELETE', "/api/v2/quarantine/{$this->mine}")->assertForbidden();
        $this->assertNotNull($this->itemRow($this->mine));
    }

    public function test_a_key_acts_with_its_owners_current_flags(): void
    {
        $key = $this->keyHeader($this->apiKey('reseller'));
        $second = $this->item('anna@example.org');

        $this->send('POST', "/api/v2/quarantine/{$this->mine}/release", null, $key)->assertOk();
        DB::table('da_acl')->where('username', 'reseller')->update(['quarantine' => 0]);
        $this->send('POST', "/api/v2/quarantine/{$second}/release", null, $key)->assertForbidden()->assertJsonPath('requiredFlags', ['quarantine']);

        $this->mailer->assertDelivered(1);
    }

    public function test_an_admin_and_its_keys_reach_every_item(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $key = $this->keyHeader($this->apiKey('admin'));

        $this->assertSame(2, $this->getJson('/api/v2/quarantine', $key)->assertOk()->json('meta.total'));
        $this->send('DELETE', "/api/v2/quarantine/{$this->foreign}", null, $key)->assertNoContent();
    }

    public function test_a_domain_admin_key_never_reaches_another_domain(): void
    {
        $key = $this->keyHeader($this->apiKey('reseller'));

        $this->send('DELETE', "/api/v2/quarantine/{$this->foreign}", null, $key)->assertNotFound();
        $this->get("/api/v2/quarantine/{$this->foreign}/message", $key)->assertNotFound();
        $this->assertNotNull($this->itemRow($this->foreign));
    }

    public function test_a_restricted_session_is_refused(): void
    {
        $this->mailcow()->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $this->signInAs('restricted', Role::Admin);

        $this->getJson('/api/v2/quarantine')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
        $this->send('DELETE', "/api/v2/quarantine/{$this->mine}")->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
    }
}
