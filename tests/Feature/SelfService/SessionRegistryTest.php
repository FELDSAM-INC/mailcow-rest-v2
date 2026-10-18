<?php

namespace Tests\Feature\SelfService;

use App\Auth\AccountKind;
use App\Auth\Role;
use App\Services\Auth\SessionManager;
use App\Services\Auth\SessionRegistry;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Redis;
use Tests\Support\Auth\AuthRequests;
use Tests\TestCase;

/**
 * T010 (feature 005 research R2): the registry of an account's sessions of this API, which lets a
 * password change and a completed reset end the account's other sessions.
 */
class SessionRegistryTest extends TestCase
{
    use AuthRequests;

    private function registry(): SessionRegistry
    {
        return $this->app->make(SessionRegistry::class);
    }

    /**
     * @return array<string, string> handle => session ID
     */
    private function entries(string $kind, string $username): array
    {
        $entries = Redis::connection('restv2')->command('hgetall', ["account-sessions:{$kind}:{$username}"]);

        return is_array($entries) ? $entries : [];
    }

    private function handleOf(string $sessionId): string
    {
        $store = new Store($this->sessionCookieName(), $this->app['session']->driver()->getHandler(), $sessionId, 'json');
        $store->start();

        return (string) $store->get(SessionManager::HANDLE);
    }

    public function test_a_sign_in_registers_the_session_under_a_stable_handle(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');

        $this->passwordSignIn('anna@example.org')->assertCreated();
        $sessionId = (string) $this->sessionCookie();
        $handle = $this->handleOf($sessionId);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $handle);
        $this->assertSame([$handle => $sessionId], $this->entries('mailbox', 'anna@example.org'));
    }

    public function test_the_handle_survives_a_renewal_and_the_entry_follows_the_new_identifier(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $sessionId = $this->signInAs('anna@example.org', Role::MailboxUser);
        $handle = $this->handleOf($sessionId);

        $store = new Store($this->sessionCookieName(), $this->app['session']->driver()->getHandler(), $sessionId, 'json');
        $store->start();
        $this->app->make(SessionManager::class)->renew($store);
        $store->save();

        $this->assertNotSame($sessionId, $store->getId());
        $this->assertSame($handle, $store->get(SessionManager::HANDLE));
        $this->assertSame([$handle => $store->getId()], $this->entries('mailbox', 'anna@example.org'));
    }

    public function test_sign_out_forgets_the_session(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->signInAs('anna@example.org', Role::MailboxUser);

        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();

        $this->assertSame([], $this->entries('mailbox', 'anna@example.org'));
    }

    public function test_is_alive_is_false_for_a_destroyed_session(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $sessionId = $this->signInAs('anna@example.org', Role::MailboxUser);
        $handle = $this->handleOf($sessionId);

        $this->assertTrue($this->registry()->isAlive(AccountKind::Mailbox, 'anna@example.org', $handle));

        $this->app['session']->driver()->getHandler()->destroy($sessionId);

        $this->assertFalse($this->registry()->isAlive(AccountKind::Mailbox, 'anna@example.org', $handle));
        $this->assertFalse($this->registry()->isAlive(AccountKind::Mailbox, 'anna@example.org', 'unknown'));
    }

    public function test_end_all_destroys_the_other_sessions_prunes_dead_entries_and_counts_the_ended_ones(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $first = $this->signInAs('anna@example.org', Role::MailboxUser);
        $second = $this->signInAs('anna@example.org', Role::MailboxUser);
        $current = $this->signInAs('anna@example.org', Role::MailboxUser);
        $dead = $this->signInAs('anna@example.org', Role::MailboxUser);
        $this->app['session']->driver()->getHandler()->destroy($dead);

        $ended = $this->registry()->endAll(AccountKind::Mailbox, 'anna@example.org', $this->handleOf($current));

        $this->assertSame(2, $ended);
        $this->assertSame([$this->handleOf($current) => $current], $this->entries('mailbox', 'anna@example.org'));

        foreach ([$first, $second] as $sessionId) {
            $this->useSessionCookie($sessionId);
            $this->getJson('/api/v2/me')->assertStatus(401)->assertJsonPath('type', '/api/v2/problems/unauthenticated');
        }

        $this->useSessionCookie($current);
        $this->getJson('/api/v2/me')->assertOk();
    }

    public function test_end_all_without_an_exception_ends_every_session(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->signInAs('admin', Role::Admin);
        $this->signInAs('admin', Role::Admin);

        $this->assertSame(2, $this->registry()->endAll(AccountKind::Admin, 'admin'));
        $this->assertSame([], $this->entries('admin', 'admin'));
    }

    public function test_every_session_request_refreshes_the_lifetime_of_the_entries(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->signInAs('anna@example.org', Role::MailboxUser);
        Redis::connection('restv2')->command('expire', ['account-sessions:mailbox:anna@example.org', 5]);

        $this->getJson('/api/v2/me')->assertOk();

        $ttl = (int) Redis::connection('restv2')->command('ttl', ['account-sessions:mailbox:anna@example.org']);
        $this->assertGreaterThan(7000, $ttl);
        $this->assertLessThanOrEqual(7200, $ttl);
    }
}
