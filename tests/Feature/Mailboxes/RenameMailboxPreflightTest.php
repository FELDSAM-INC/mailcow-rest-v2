<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 003 T080 (FR-044, US8 AS-2, AS-3, AS-6): every check runs before anything changes.
 */
class RenameMailboxPreflightTest extends TestCase
{
    use ApiKeys;
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org', attributes: ['aliases' => 2]);
        $this->mailcow()->legacyMailbox('anna@example.org');
        $this->redis()->hSet('RL_VALUE', 'anna@example.org', '10 / 1h');
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function rename(string $newLocalPart, bool $keep = false, array $headers = [], string $address = 'anna@example.org'): TestResponse
    {
        return $this->postJson('/api/v2/mailboxes/'.$address.'/rename', ['newLocalPart' => $newLocalPart, 'keepOldAddressAsAlias' => $keep], $this->csrf() + $headers);
    }

    private function assertUnchanged(): void
    {
        $this->assertNotNull($this->mailboxRow('anna@example.org'));
        $this->assertSame('10 / 1h', $this->redis()->hGet('RL_VALUE', 'anna@example.org'));
        $writes = array_filter($this->dockerApi()->commands(), fn (array $c): bool => ($c[1]['task'] ?? '') !== 'get_acl');
        $this->assertSame([], array_values($writes), 'No container write was sent.');
    }

    public function test_a_taken_address_is_refused_with_its_holder(): void
    {
        $this->mailcow()->legacyMailbox('bob@example.org');
        $this->mailcow()->mailbox('room@example.org', kind: 'location');
        $this->mailcow()->alias('sales@example.org', 'bob@example.org');
        $this->mailcow()->temporaryAlias('tmp@example.org', 'bob@example.org');
        $this->asAdmin();

        foreach (['bob' => 'mailbox', 'room' => 'resource', 'sales' => 'alias', 'tmp' => 'temporaryAlias'] as $local => $holder) {
            $this->rename($local)->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/address-in-use')->assertJsonPath('holder', $holder);
        }

        $this->assertUnchanged();
    }

    public function test_an_inactive_mailbox_cannot_be_renamed(): void
    {
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['active' => 0]);
        $this->asAdmin();

        $this->rename('new')->assertStatus(409)->assertJsonPath('type', '/api/v2/problems/mailbox-not-renamable');
        $this->assertUnchanged();
    }

    public function test_the_same_or_an_invalid_local_part_is_refused(): void
    {
        $this->asAdmin();

        $this->rename('anna')->assertStatus(422)->assertJsonPath('errors.newLocalPart.0.code', 'unchanged');
        $this->rename(' ANNA ')->assertStatus(422)->assertJsonPath('errors.newLocalPart.0.code', 'unchanged');
        $this->rename('.anna')->assertStatus(422)->assertJsonPath('errors.newLocalPart.0.code', 'invalidFormat');
        $this->assertUnchanged();
    }

    public function test_the_alias_limit_is_checked_only_when_the_old_address_is_kept(): void
    {
        $this->mailcow()->alias('one@example.org', 'anna@example.org');
        $this->mailcow()->alias('two@example.org', 'anna@example.org');
        $this->asAdmin();

        $this->rename('new', true)->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/domain-alias-limit-reached')
            ->assertJsonPath('domain', 'example.org')
            ->assertJsonPath('count', 2)
            ->assertJsonPath('limit', 2)
            ->assertJsonPath('requested', 1);
        $this->assertUnchanged();

        $this->rename('new', false)->assertOk();
    }

    public function test_unreachable_services_refuse_before_anything_changes(): void
    {
        $this->asAdmin();

        $this->dockerApi()->setReachable('dovecot-mailcow', false);
        $this->rename('new')->assertStatus(503)->assertJsonPath('dependency', 'mailStore');
        $this->dockerApi()->setReachable('dovecot-mailcow', true);
        $this->dockerApi()->setReachable('sogo-mailcow', false);
        $this->rename('new')->assertStatus(503)->assertJsonPath('dependency', 'webmail');
        $this->assertUnchanged();

        config(['restv2.skip_sogo' => true]);
        $this->rename('new')->assertOk();
    }

    public function test_an_unavailable_shared_store_refuses(): void
    {
        $this->asAdmin();
        $csrf = $this->csrf();
        $this->breakSharedStore();

        $this->postJson('/api/v2/mailboxes/anna@example.org/rename', ['newLocalPart' => 'new', 'keepOldAddressAsAlias' => false], $csrf)
            ->assertStatus(503)->assertJsonPath('dependency', 'sharedStore');
        $this->assertNotNull($this->mailboxRow('anna@example.org'));
        $this->dockerApi()->assertNothingCalled();
    }

    public function test_an_outdated_version_scope_and_roles(): void
    {
        $this->mailcow()->domain('other.example');
        $this->mailcow()->legacyMailbox('carl@other.example');
        $this->asDomainAdmin(['example.org']);
        $etag = (string) $this->getJson('/api/v2/mailboxes/anna@example.org')->headers->get('ETag');
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['name' => 'Changed']);

        $this->rename('new', false, ['If-Match' => $etag])->assertStatus(412);
        $this->rename('new', false, [], 'carl@other.example')->assertNotFound();
        $this->assertUnchanged();

        $this->forgetCookies();
        $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        $this->postJson('/api/v2/mailboxes/anna@example.org/rename', ['newLocalPart' => 'new', 'keepOldAddressAsAlias' => false], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->rename('new')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/forbidden');
    }
}
