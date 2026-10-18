<?php

namespace Tests\Feature\Mailboxes;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T066 (FR-035, US5 AS-1, AS-2, AS-5): user permissions change one by one, take effect
 * on the user's next request, and overlapping permissions stay hidden and unchangeable for a domain
 * administrator lacking the flag.
 */
class UpdateMailboxPermissionsTest extends TestCase
{
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org', userAcl: ['spam_alias' => 0, 'syncjobs' => 0]);
    }

    public function test_only_the_sent_permissions_change_and_apply_at_once(): void
    {
        $this->asAdmin();

        $this->patchMailbox('anna@example.org', ['userPermissions' => ['syncjobs' => true]])->assertOk()->assertJsonPath('data.userPermissions.syncjobs', true);

        $acl = DB::table('user_acl')->where('username', 'anna@example.org')->first();
        $this->assertSame([1, 0, 1], [(int) $acl?->syncjobs, (int) $acl?->spam_alias, (int) $acl?->pw_reset]);
        $this->assertSame(['userPermissions.syncjobs' => ['old' => false, 'new' => true]], $this->auditOf('mailboxUpdate')[0]->details['changes']);

        $this->forgetCookies();
        $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.permissions.syncjobs', true);
    }

    public function test_a_domain_administrator_lacking_a_flag_neither_sees_nor_changes_its_permission(): void
    {
        $this->asDomainAdmin(['example.org'], daAcl: ['syncjobs' => 0]);

        $this->assertArrayNotHasKey('syncjobs', (array) $this->getJson('/api/v2/mailboxes/anna@example.org')->assertOk()->json('data.userPermissions'));
        $this->patchMailbox('anna@example.org', ['userPermissions' => ['syncjobs' => true]])->assertStatus(403)
            ->assertJsonPath('requiredFlags', ['userPermissions.syncjobs' => 'syncjobs']);
        $this->patchMailbox('anna@example.org', ['userPermissions' => ['spamScore' => false]])->assertOk()->assertJsonMissingPath('data.userPermissions.syncjobs');

        $acl = DB::table('user_acl')->where('username', 'anna@example.org')->first();
        $this->assertSame([0, 0], [(int) $acl?->spam_score, (int) $acl?->syncjobs]);
    }

    public function test_mailbox_users_cannot_change_their_own_permissions(): void
    {
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $this->patchMailbox('anna@example.org', ['userPermissions' => ['syncjobs' => true]])->assertStatus(403);
    }
}
