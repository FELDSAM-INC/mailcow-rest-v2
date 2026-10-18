<?php

namespace Tests\Feature\Passkeys;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Tests\Support\Auth\AuthRequests;
use Tests\Support\WebAuthn\VirtualAuthenticator;
use Tests\Support\WebAuthn\VirtualCredential;
use Tests\TestCase;

/**
 * T071 (FR-053, FR-058, AS-7): listing, reading, renaming and removing the own passkeys, legacy
 * ones included. Other accounts' passkeys answer 404 for every role.
 */
class PasskeyManagementTest extends TestCase
{
    use AuthRequests;

    private const HOST = 'https://mail.example.org';

    private VirtualAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authenticator = new VirtualAuthenticator;
    }

    private function passkey(string $username, ?string $name = null, ?string $subject = null, string $created = '2026-01-15 10:00:00', int $active = 1): VirtualCredential
    {
        $credential = $this->authenticator->createCredential('mail.example.org', $username);
        $this->mailcow()->fido2Credential($username, $credential, $name, $active, $subject);
        DB::table('fido2')->where('credentialId', $credential->id)->update(['created' => $created]);

        return $credential;
    }

    private function id(VirtualCredential $credential): string
    {
        return hash('sha256', $credential->id);
    }

    public function test_the_list_shows_legacy_and_api_passkeys(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        // 10:00 in Europe/Prague (winter, UTC+1) is 09:00 UTC.
        $legacy = $this->passkey('alice@example.org', null, null, '2026-01-15 10:00:00');
        $named = $this->passkey('alice@example.org', 'YubiKey 5C', 'CN=Yubico U2F EE Serial 1234', '2026-07-01 12:30:00', 0);
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->passkey('bob@example.org', 'Bob');
        $this->signInAs('alice@example.org', Role::MailboxUser);

        $response = $this->getJson('/api/v2/me/passkeys')->assertOk();

        $response->assertJsonPath('meta', ['total' => 2, 'limit' => 25, 'offset' => 0]);
        $data = $response->json('data');
        $this->assertSame([$this->id($legacy), $this->id($named)], array_column($data, 'id'));
        $this->assertSame([
            'id' => $this->id($legacy),
            'name' => null,
            'authenticatorDescription' => null,
            'registeredAt' => '2026-01-15T09:00:00Z',
            'hostName' => 'mail.example.org',
            'active' => true,
        ], array_diff_key($data[0], ['etag' => true]));
        $this->assertSame('YubiKey 5C', $data[1]['name']);
        $this->assertSame('CN=Yubico U2F EE Serial 1234', $data[1]['authenticatorDescription']);
        $this->assertSame('2026-07-01T10:30:00Z', $data[1]['registeredAt']);
        $this->assertFalse($data[1]['active']);

        foreach ($data as $entry) {
            $this->getJson('/api/v2/me/passkeys/'.$entry['id'])->assertOk()->assertHeader('ETag', $entry['etag']);
        }
    }

    public function test_the_list_sorts_pages_and_searches(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $b = $this->passkey('admin', 'Bravo', null, '2026-01-01 10:00:00');
        $a = $this->passkey('admin', 'Alpha', 'CN=Feitian', '2026-02-01 10:00:00');
        $c = $this->passkey('admin', 'Charlie 100%', null, '2026-03-01 10:00:00');
        $this->signInAs('admin', Role::Admin);

        $this->assertSame([$this->id($b), $this->id($a), $this->id($c)], array_column($this->getJson('/api/v2/me/passkeys')->json('data'), 'id'));
        $this->assertSame([$this->id($a), $this->id($b), $this->id($c)], array_column($this->getJson('/api/v2/me/passkeys?sort=name')->json('data'), 'id'));
        $this->assertSame([$this->id($c), $this->id($b), $this->id($a)], array_column($this->getJson('/api/v2/me/passkeys?sort=name&order=desc')->json('data'), 'id'));

        $page = $this->getJson('/api/v2/me/passkeys?limit=1&offset=1')->assertOk();
        $page->assertJsonPath('meta', ['total' => 3, 'limit' => 1, 'offset' => 1]);
        $this->assertSame([$this->id($a)], array_column($page->json('data'), 'id'));

        $this->getJson('/api/v2/me/passkeys?limit=1000')->assertOk()->assertJsonPath('meta.limit', 1000);

        // `q` searches the name and the authenticator description, case-insensitively.
        $this->assertSame([$this->id($a)], array_column($this->getJson('/api/v2/me/passkeys?q=feitian')->json('data'), 'id'));
        $this->assertSame([$this->id($b)], array_column($this->getJson('/api/v2/me/passkeys?q=RAV')->json('data'), 'id'));
        $this->assertSame([$this->id($c)], array_column($this->getJson('/api/v2/me/passkeys?q=0%25')->json('data'), 'id'));
        $this->getJson('/api/v2/me/passkeys?q=_')->assertJsonPath('meta.total', 0);
    }

    public function test_invalid_list_parameters_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->getJson('/api/v2/me/passkeys?limit=1001')->assertUnprocessable()->assertJsonPath('errors.limit.0.code', 'outOfRange');
        $this->getJson('/api/v2/me/passkeys?sort=created')->assertUnprocessable()->assertJsonPath('errors.sort.0.code', 'notInList');
    }

    public function test_a_passkey_is_read_with_its_version_tag(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $credential = $this->passkey('admin', 'Key');
        $this->signInAs('admin', Role::Admin);

        $response = $this->getJson('/api/v2/me/passkeys/'.$this->id($credential))->assertOk();

        $response->assertJsonPath('data.id', $this->id($credential))->assertJsonPath('data.name', 'Key');
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertMatchesRegularExpression('/^"[A-Za-z0-9_-]+"$/', (string) $response->headers->get('ETag'));
    }

    public function test_renaming_with_the_current_tag(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $credential = $this->passkey('admin', 'Old name');
        $this->signInAs('admin', Role::Admin);
        $etag = (string) $this->getJson('/api/v2/me/passkeys/'.$this->id($credential))->headers->get('ETag');

        $response = $this->patchMerge('/api/v2/me/passkeys/'.$this->id($credential), ['name' => '  MacBook Air '], [
            'X-CSRF-Token' => $this->csrfToken(),
            'If-Match' => $etag,
        ])->assertOk();

        $response->assertJsonPath('data.name', 'MacBook Air');
        $this->assertNotSame($etag, $response->headers->get('ETag'));
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
        $this->assertSame('MacBook Air', DB::table('fido2')->where('credentialId', $credential->id)->value('friendlyName'));

        $audit = $this->onlyAuditRecord('passkeyRename');
        $this->assertSame('passkey', $audit->target_type);
        $this->assertSame($this->id($credential), $audit->target_id);
        $this->assertSame('MacBook Air', $audit->target_name);
        $this->assertSame('admin', $audit->actor_username);
    }

    public function test_renaming_without_a_tag_and_to_the_same_name(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $credential = $this->passkey('admin', 'Key');
        $this->signInAs('admin', Role::Admin);

        $this->patchMerge('/api/v2/me/passkeys/'.$this->id($credential), ['name' => 'Key'], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertOk()
            ->assertJsonPath('data.name', 'Key');

        // Nothing changed, nothing audited.
        $this->assertSame([], $this->auditRecords('passkeyRename'));
    }

    public function test_a_stale_tag_is_refused(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $credential = $this->passkey('admin', 'Key');
        $this->signInAs('admin', Role::Admin);
        $etag = (string) $this->getJson('/api/v2/me/passkeys/'.$this->id($credential))->headers->get('ETag');
        DB::table('fido2')->where('credentialId', $credential->id)->update(['friendlyName' => 'Renamed in the legacy UI']);

        $this->patchMerge('/api/v2/me/passkeys/'.$this->id($credential), ['name' => 'Mine'], ['X-CSRF-Token' => $this->csrfToken(), 'If-Match' => $etag])
            ->assertStatus(412)
            ->assertJsonPath('type', 'about:blank');
        $this->deleteJson('/api/v2/me/passkeys/'.$this->id($credential), [], ['X-CSRF-Token' => $this->csrfToken(), 'If-Match' => $etag])
            ->assertStatus(412);

        $this->assertSame('Renamed in the legacy UI', DB::table('fido2')->where('credentialId', $credential->id)->value('friendlyName'));
        $this->assertSame([], $this->auditRecords('passkeyRename'));
    }

    public function test_an_invalid_name_is_refused(): void
    {
        $this->withoutRequestValidation();
        $this->mailcow()->admin('admin', 'x');
        $credential = $this->passkey('admin', 'Key');
        $this->signInAs('admin', Role::Admin);
        $uri = '/api/v2/me/passkeys/'.$this->id($credential);

        $this->patchMerge($uri, ['name' => str_repeat('x', 256)], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0.code', 'tooLong')
            ->assertJsonPath('errors.name.0.params', ['max' => 255]);
        $this->patchMerge($uri, ['name' => ' '], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0.code', 'required');
        $this->patchMerge($uri, [], ['X-CSRF-Token' => $this->csrfToken()])->assertUnprocessable();
    }

    public function test_removing_a_passkey(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $credential = $this->passkey('alice@example.org', 'Only key');
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $etag = (string) $this->getJson('/api/v2/me/passkeys/'.$this->id($credential))->headers->get('ETag');

        // Removing the last passkey is allowed.
        $this->deleteJson('/api/v2/me/passkeys/'.$this->id($credential), [], ['X-CSRF-Token' => $this->csrfToken(), 'If-Match' => $etag])->assertNoContent();

        $this->assertSame(0, DB::table('fido2')->count());
        $this->getJson('/api/v2/me/passkeys/'.$this->id($credential))->assertNotFound();
        $audit = $this->onlyAuditRecord('passkeyRemoval');
        $this->assertSame($this->id($credential), $audit->target_id);
        $this->assertSame('Only key', $audit->target_name);

        // The removed passkey no longer signs in.
        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();
        $challenge = $this->postEmpty(self::HOST.'/api/v2/session/passkey-challenge', ['X-CSRF-Token' => $this->csrfToken()])->json('data.publicKey');
        $this->postJson(self::HOST.'/api/v2/session/passkey-assertion', [
            'credential' => $this->authenticator->assert($credential, $challenge, self::HOST),
        ], ['X-CSRF-Token' => $this->csrfToken()])->assertUnauthorized();
    }

    public function test_another_accounts_passkey_is_not_found_for_every_role(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->mailbox('bob@example.org');
        $bobs = $this->passkey('bob@example.org', 'Bob');
        $this->mailcow()->admin('admin', 'x');
        $this->mailcow()->domainAdmin('reseller', ['example.org']);
        $this->mailcow()->mailbox('alice@example.org');
        $uri = '/api/v2/me/passkeys/'.$this->id($bobs);

        foreach ([['admin', Role::Admin], ['reseller', Role::DomainAdmin], ['alice@example.org', Role::MailboxUser]] as [$username, $role]) {
            $this->forgetCookies();
            $this->signInAs($username, $role);

            $this->getJson($uri)->assertNotFound();
            $this->patchMerge($uri, ['name' => 'Mine'], ['X-CSRF-Token' => $this->csrfToken()])->assertNotFound();
            $this->deleteJson($uri, [], ['X-CSRF-Token' => $this->csrfToken()])->assertNotFound();
            $this->assertSame(0, $this->getJson('/api/v2/me/passkeys')->json('meta.total'));
        }

        $this->assertSame('Bob', DB::table('fido2')->where('username', 'bob@example.org')->value('friendlyName'));
        $this->assertSame([], $this->auditRecords('passkeyRename'));
        $this->assertSame([], $this->auditRecords('passkeyRemoval'));
    }

    public function test_an_unknown_passkey_is_not_found(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->getJson('/api/v2/me/passkeys/'.str_repeat('0', 64))->assertNotFound()->assertJsonPath('type', 'about:blank');
    }

    public function test_a_restricted_session_cannot_manage_passkeys(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1']);
        $credential = $this->passkey('bob@example.org', 'Key');
        $this->signInAs('bob@example.org', Role::MailboxUser);
        $uri = '/api/v2/me/passkeys/'.$this->id($credential);

        $this->getJson('/api/v2/me/passkeys')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
        $this->getJson($uri)->assertForbidden();
        $this->patchMerge($uri, ['name' => 'X'], ['X-CSRF-Token' => $this->csrfToken()])->assertForbidden();
        $this->deleteJson($uri, [], ['X-CSRF-Token' => $this->csrfToken()])->assertForbidden();
    }

    public function test_management_needs_a_signed_in_session(): void
    {
        $this->getJson('/api/v2/me/passkeys')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/unauthenticated');

        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->signInPending('alice@example.org', Role::MailboxUser);
        $this->getJson('/api/v2/me/passkeys')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/second-factor-required');
    }

    public function test_changes_need_the_anti_forgery_token(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $credential = $this->passkey('admin', 'Key');
        $this->signInAs('admin', Role::Admin);

        $this->deleteJson('/api/v2/me/passkeys/'.$this->id($credential))
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
        $this->assertSame(1, DB::table('fido2')->count());
    }
}
