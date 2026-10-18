<?php

namespace Tests\Feature\Aliases;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\TestCase;

/**
 * Feature 004 T034 and T043 (FR-003–FR-005, SC-004, US2, US3): who may create, change and delete
 * which aliases, and which refusals are audited.
 */
class AliasWriteAuthorizationTest extends TestCase
{
    use AliasTestHelpers;
    use ApiKeys;

    private int $own = 0;

    private int $foreign = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $this->mailbox('anna@example.org');
        $this->own = $f->alias('own@example.org', 'anna@example.org');
        $this->foreign = $f->alias('other@foreign.example', 'anna@example.org');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(string $address = 'new@example.org'): array
    {
        return ['address' => $address, 'destinations' => ['anna@example.org']];
    }

    public function test_administrators_create_with_a_session_and_csrf_token_or_a_read_write_key(): void
    {
        $this->signInAs('admin', Role::Admin);
        $this->postJson('/api/v2/aliases', self::body('a@example.org'))->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->createAlias(self::body('a@example.org'))->assertCreated();
        $this->forgetCookies();

        $this->postJson('/api/v2/aliases', self::body('b@foreign.example'), $this->keyHeader($this->apiKey('admin')))->assertCreated();
        $this->postJson('/api/v2/aliases', self::body('c@example.org'), $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->postJson('/api/v2/aliases', self::body('d@example.org'))->assertStatus(401);

        $records = $this->auditOf('aliasCreation');
        $failures = array_values(array_filter($records, fn (object $r): bool => $r->outcome === 'failure'));
        $this->assertCount(1, $failures, 'Neither the CSRF failure nor the 401 is audited.');
        $this->assertSame('permissionDenied', $failures[0]->failure_reason);
        $this->assertSame('c@example.org', $failures[0]->target_name);
    }

    public function test_domain_administrators_create_only_in_their_scope(): void
    {
        $key = $this->keyHeader($this->apiKey('reseller'));

        $this->postJson('/api/v2/aliases', self::body('a@example.org'), $key)->assertCreated();
        $this->postJson('/api/v2/aliases', self::body('b@foreign.example'), $key)->assertStatus(422)->assertJsonPath('errors.address.0.code', 'notFound');

        $this->signInAs('reseller', Role::DomainAdmin);
        $this->createAlias(self::body('c@example.org'))->assertCreated();
    }

    public function test_mailbox_users_and_restricted_sessions_are_refused_and_audited(): void
    {
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->createAlias(self::body())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->patchAlias($this->own, ['active' => false])->assertForbidden();
        $this->deleteAlias($this->own)->assertForbidden();

        $this->signInAs('restricted', Role::Admin);
        $this->createAlias(self::body())->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');

        $this->assertCount(2, $this->auditOf('aliasCreation'));
        $this->assertCount(1, $this->auditOf('aliasUpdate'));
        $this->assertCount(1, $this->auditOf('aliasDeletion'));
        foreach ([...$this->auditOf('aliasCreation'), ...$this->auditOf('aliasUpdate'), ...$this->auditOf('aliasDeletion')] as $record) {
            $this->assertSame(['failure', 'permissionDenied'], [$record->outcome, $record->failure_reason]);
        }
        $this->assertSame((string) $this->own, $this->auditOf('aliasUpdate')[0]->target_id);
    }

    public function test_read_only_keys_may_not_change_or_delete(): void
    {
        $key = $this->keyHeader($this->apiKey('admin', 'readOnly'));

        $this->patchMerge("/api/v2/aliases/{$this->own}", ['active' => false], $key)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->deleteJson("/api/v2/aliases/{$this->own}", [], $key)->assertStatus(403)->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');

        $this->assertSame(1, (int) $this->aliasRow($this->own)?->active);
        $this->assertSame('permissionDenied', $this->auditOf('aliasUpdate')[0]->failure_reason);
        $this->assertSame('permissionDenied', $this->auditOf('aliasDeletion')[0]->failure_reason);
    }

    public function test_domain_administrators_get_not_found_for_foreign_aliases_and_cannot_move_into_foreign_domains(): void
    {
        $this->signInAs('reseller', Role::DomainAdmin);

        $this->patchAlias($this->foreign, ['active' => false])->assertNotFound()->assertJsonPath('type', 'about:blank');
        $this->deleteAlias($this->foreign)->assertNotFound();
        $this->patchAlias($this->own, ['address' => 'moved@foreign.example'])->assertStatus(422)->assertJsonPath('errors.address.0.code', 'notFound');

        $this->assertSame(1, (int) $this->aliasRow($this->foreign)?->active);
        $this->assertSame('own@example.org', $this->aliasRow($this->own)?->address);
        $updates = $this->auditOf('aliasUpdate');
        $this->assertSame(['notFound', 'validationFailed'], array_column($updates, 'failure_reason'));
        $this->assertSame((string) $this->foreign, $updates[0]->target_id);
        $this->assertSame('other@foreign.example', $updates[0]->target_name);
        $this->assertSame('notFound', $this->auditOf('aliasDeletion')[0]->failure_reason);
        $this->assertSame(1, DB::table('alias')->where('id', $this->foreign)->count());
    }
}
