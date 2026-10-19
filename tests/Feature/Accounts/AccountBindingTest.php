<?php

namespace Tests\Feature\Accounts;

use App\Auth\Role;
use App\Services\Accounts\AccountGeneration;
use Illuminate\Support\Facades\DB;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\Support\Redis\ChannelSpy;
use Tests\TestCase;

/**
 * Feature 007 T009 (FR-036, research R11): sessions and API keys are bound to the account's
 * creation time, so that nothing acts for a later account with the same username after a legacy
 * deletion and re-creation; impersonation targets carry a generation counter.
 */
class AccountBindingTest extends TestCase
{
    use AccountTestHelpers;

    private const IP = '192.0.2.9';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withServerVariables(['REMOTE_ADDR' => self::IP]);
        $this->mailcow()->ensureDomain('a.example');
    }

    private function recreateAdminRowLater(string $username): void
    {
        $row = (array) DB::table('admin')->where('username', $username)->first();
        DB::table('admin')->where('username', $username)->delete();
        $row['created'] = DB::raw('NOW() + INTERVAL 1 DAY');
        DB::table('admin')->insert($row);
    }

    public function test_a_session_ends_when_its_account_was_deleted_and_created_again(): void
    {
        $this->mailcow()->domainAdmin('reseller1', ['a.example'], created: '2026-01-01 10:00:00');
        $this->signInAs('reseller1', Role::DomainAdmin);
        $this->getJson('/api/v2/me')->assertOk()->assertJsonPath('data.username', 'reseller1');

        $this->recreateAdminRowLater('reseller1');

        $this->getJson('/api/v2/me')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/unauthenticated');
    }

    public function test_a_mailbox_session_ends_when_its_mailbox_was_deleted_and_created_again(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@a.example', created: '2026-01-01 10:00:00');
        $this->signInAs('bob@a.example', Role::MailboxUser);
        $this->getJson('/api/v2/me')->assertOk();

        DB::table('mailbox')->where('username', 'bob@a.example')->update(['created' => DB::raw('NOW() + INTERVAL 1 DAY')]);

        $this->getJson('/api/v2/me')->assertUnauthorized();
    }

    public function test_the_session_of_an_unchanged_account_keeps_working(): void
    {
        $this->mailcow()->domainAdmin('reseller1', ['a.example']);
        $this->signInAs('reseller1', Role::DomainAdmin);

        DB::table('admin')->where('username', 'reseller1')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "0"}']);

        $this->getJson('/api/v2/me')->assertOk();
        $this->getJson('/api/v2/me')->assertOk();
    }

    public function test_a_key_of_a_later_account_with_the_same_username_is_invalid(): void
    {
        $this->mailcow()->domainAdmin('reseller1', ['a.example'], created: '2026-01-01 10:00:00');
        $key = $this->apiKey('reseller1');
        DB::table('restv2_api_keys')->where('id', $key['id'])->update(['owner_created_at' => '2026-01-01 10:00:00']);
        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk();

        $this->recreateAdminRowLater('reseller1');
        $channel = ChannelSpy::fail2ban();

        $this->getJson('/api/v2/me', $this->keyHeader($key))
            ->assertUnauthorized()
            ->assertJsonPath('type', '/api/v2/problems/api-key-invalid');
        $channel->assertPublished('mailcow UI: Invalid password for API_USER by '.self::IP);
    }

    public function test_a_key_of_an_unchanged_owner_keeps_working(): void
    {
        $this->mailcow()->admin('admin', 'x', created: '2026-01-01 10:00:00');
        $key = $this->apiKey('admin');
        DB::table('restv2_api_keys')->where('id', $key['id'])->update(['owner_created_at' => '2026-01-01 10:00:00']);

        $this->getJson('/api/v2/me', $this->keyHeader($key))->assertOk()->assertJsonPath('data.role', 'admin');
    }

    public function test_the_account_generation_starts_at_zero_and_is_bumped(): void
    {
        $generations = $this->app->make(AccountGeneration::class);

        $this->assertSame(0, $generations->current('mailbox', 'bob@a.example'));
        $this->assertSame(1, $generations->bump('mailbox', 'Bob@A.example'));
        $this->assertSame(1, $generations->current('mailbox', 'bob@a.example'));
        $this->assertSame(2, $generations->bump('mailbox', 'bob@a.example'));
        $this->assertSame(0, $generations->current('admin', 'bob@a.example'));
    }
}
