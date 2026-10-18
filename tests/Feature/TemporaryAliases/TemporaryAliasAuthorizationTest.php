<?php

namespace Tests\Feature\TemporaryAliases;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T081 (feature 005 FR-001–FR-006, US6 AS-4): who may read and change which mailbox's temporary
 * aliases.
 */
class TemporaryAliasAuthorizationTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailboxWithDomain('dora@example.net');
        $this->mailcow()->temporaryAliasRow('annaaa.alias@example.org', 'anna@example.org');
        $this->mailcow()->temporaryAliasRow('doraaa.alias@example.net', 'dora@example.net');
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<int>
     */
    private function operations(string $mailbox, string $alias, array $headers, bool $session = true): array
    {
        $base = '/api/v2/mailboxes/'.$mailbox.'/temporary-aliases';
        $domain = substr($mailbox, (int) strpos($mailbox, '@') + 1);
        $csrf = fn (): array => $session ? $this->csrf() : [];

        return [
            $this->getJson($base, $headers)->status(),
            $this->getJson($base.'/'.$alias, $headers)->status(),
            $this->postJson($base, ['domain' => $domain], $headers + $csrf())->status(),
            $this->patchMerge($base.'/'.$alias, ['permanent' => true], $headers + $csrf())->status(),
            $this->deleteJson($base.'/'.$alias, [], $headers + $csrf())->status(),
        ];
    }

    public function test_a_mailbox_user_needs_the_flag_to_change(): void
    {
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->assertSame([200, 200, 201, 200, 204], $this->operations('anna@example.org', 'annaaa.alias@example.org', []));
        $this->assertSame([404, 404, 404, 404, 404], $this->operations('dora@example.net', 'doraaa.alias@example.net', []));

        DB::table('user_acl')->where('username', 'anna@example.org')->update(['spam_alias' => 0]);
        $this->mailcow()->temporaryAliasRow('second.alias@example.org', 'anna@example.org');
        $this->assertSame([200, 200, 403, 403, 403], $this->operations('anna@example.org', 'second.alias@example.org', []));
        $this->postJson('/api/v2/mailboxes/anna@example.org/temporary-aliases', ['domain' => 'example.org'], $this->csrf())
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['spamAlias']);
    }

    public function test_a_domain_administrator_reaches_only_the_own_domains_and_holds_the_flag(): void
    {
        $this->asDomainAdmin(['example.org']);

        $this->assertSame([200, 200, 201, 200, 204], $this->operations('anna@example.org', 'annaaa.alias@example.org', []));
        $this->assertSame([404, 404, 404, 404, 404], $this->operations('dora@example.net', 'doraaa.alias@example.net', []));
        // Another mailbox's alias under an own mailbox.
        $this->getJson('/api/v2/mailboxes/anna@example.org/temporary-aliases/doraaa.alias@example.net')->assertNotFound();
    }

    public function test_an_administrator_and_keys(): void
    {
        $this->asAdmin();
        $this->assertSame([200, 200, 201, 200, 204], $this->operations('dora@example.net', 'doraaa.alias@example.net', []));

        $this->forgetCookies();
        $this->mailcow()->temporaryAliasRow('third.alias@example.org', 'anna@example.org');
        $readOnly = $this->keyHeader($this->apiKey('admin', 'readOnly'));
        $this->assertSame([200, 200, 403, 403, 403], $this->operations('anna@example.org', 'third.alias@example.org', $readOnly, false));
    }

    public function test_refusals_are_audited(): void
    {
        DB::table('user_acl')->where('username', 'anna@example.org')->update(['spam_alias' => 0]);
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $this->deleteJson('/api/v2/mailboxes/anna@example.org/temporary-aliases/annaaa.alias@example.org', [], $this->csrf())->assertStatus(403);

        $record = $this->onlyAuditRecord('temporaryAliasDeletion');
        $this->assertSame(['failure', 'permissionDenied', 'anna@example.org', 'annaaa.alias@example.org'], [$record->outcome, $record->failure_reason, $record->target_id, $record->target_name]);
    }
}
