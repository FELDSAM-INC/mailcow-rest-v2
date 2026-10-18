<?php

namespace Tests\Feature\AppPasswords;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T064 (feature 005 FR-001–FR-006, US4 AS-2, AS-4, SC-007): who may read and change the app
 * passwords of which mailbox.
 */
class AppPasswordAuthorizationTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private int $annaId;

    private int $foreignId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailboxWithDomain('dora@example.net');
        $this->mailcow()->resource('room@example.org', 'Room');
        $this->annaId = $this->mailcow()->appPasswd('anna@example.org', 'Phone');
        $this->foreignId = $this->mailcow()->appPasswd('dora@example.net', 'Laptop');
    }

    /**
     * Read, create, change and delete of one mailbox's app passwords: the statuses.
     *
     * @param  array<string, string>  $headers
     * @return list<int>
     */
    private function operations(string $mailbox, int $id, array $headers, bool $session = true): array
    {
        $base = '/api/v2/mailboxes/'.$mailbox.'/app-passwords';
        $csrf = fn (): array => $session ? $this->csrf() : [];

        return [
            $this->getJson($base, $headers)->status(),
            $this->getJson($base.'/'.$id, $headers)->status(),
            $this->postJson($base, ['name' => 'New'], $headers + $csrf())->status(),
            $this->patchMerge($base.'/'.$id, ['name' => 'Renamed'], $headers + $csrf())->status(),
            $this->deleteJson($base.'/'.$id, [], $headers + $csrf())->status(),
        ];
    }

    public function test_an_administrator_reaches_every_mailbox(): void
    {
        $this->asAdmin();

        $this->assertSame([200, 200, 201, 200, 204], $this->operations('dora@example.net', $this->foreignId, []));
    }

    public function test_a_domain_administrator_with_the_flag_reaches_only_the_own_domains(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['app_passwds' => 1]);

        $this->assertSame([200, 200, 201, 200, 204], $this->operations('anna@example.org', $this->annaId, []));
        $this->assertSame([404, 404, 404, 404, 404], $this->operations('dora@example.net', $this->foreignId, []));
        // A guessed ID of a foreign mailbox under an own mailbox.
        $this->getJson('/api/v2/mailboxes/anna@example.org/app-passwords/'.$this->foreignId)->assertNotFound();
        $this->getJson('/api/v2/mailboxes/room@example.org/app-passwords')->assertNotFound();
    }

    public function test_a_domain_administrator_without_the_flag_may_only_read(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['app_passwds' => 0]);

        $statuses = $this->operations('anna@example.org', $this->annaId, []);
        $this->assertSame([200, 200, 403, 403, 403], $statuses);
        $this->postJson('/api/v2/mailboxes/anna@example.org/app-passwords', ['name' => 'New'], $this->csrf())
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['appPasswds']);
    }

    public function test_a_mailbox_user_reaches_only_the_own_mailbox_and_needs_the_flag_to_change(): void
    {
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->assertSame([200, 200, 201, 200, 204], $this->operations('anna@example.org', $this->annaId, []));
        $this->assertSame([404, 404, 404, 404, 404], $this->operations('dora@example.net', $this->foreignId, []));

        DB::table('user_acl')->where('username', 'anna@example.org')->update(['app_passwds' => 0]);
        $id = $this->mailcow()->appPasswd('anna@example.org', 'Phone');
        $this->assertSame([200, 200, 403, 403, 403], $this->operations('anna@example.org', $id, []));
    }

    public function test_keys_follow_their_owner_and_scope(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['app_passwds' => 1]);

        $readOnly = $this->keyHeader($this->apiKey('admin', 'readOnly'));
        $this->assertSame([200, 200, 403, 403, 403], $this->operations('dora@example.net', $this->foreignId, $readOnly, false));
        $this->postJson('/api/v2/mailboxes/dora@example.net/app-passwords', ['name' => 'New'], $readOnly)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $resellerKey = $this->keyHeader($this->apiKey('reseller', 'readWrite'));
        $this->assertSame([200, 200, 201, 200, 204], $this->operations('anna@example.org', $this->annaId, $resellerKey, false));
        $this->assertSame([404, 404, 404, 404, 404], $this->operations('dora@example.net', $this->foreignId, $resellerKey, false));
    }

    public function test_a_restricted_session_is_refused(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1']);
        $this->signInAs('bob@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/mailboxes/bob@example.org/app-passwords')->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/pending-action-required');
    }

    public function test_refusals_are_audited_against_the_mailbox(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['app_passwds' => 0]);

        $this->postJson('/api/v2/mailboxes/anna@example.org/app-passwords', ['name' => 'New'], $this->csrf())->assertStatus(403);
        $this->deleteJson('/api/v2/mailboxes/dora@example.net/app-passwords/'.$this->foreignId, [], $this->csrf())->assertNotFound();

        $created = $this->onlyAuditRecord('appPasswordCreation');
        $this->assertSame(['failure', 'permissionDenied', 'anna@example.org', ['appPasswds']], [$created->outcome, $created->failure_reason, $created->target_id, $created->details['requiredFlags']]);
        $deleted = $this->onlyAuditRecord('appPasswordDeletion');
        $this->assertSame(['failure', 'notFound', 'dora@example.net'], [$deleted->outcome, $deleted->failure_reason, $deleted->target_id]);
    }
}
