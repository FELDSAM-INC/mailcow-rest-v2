<?php

namespace Tests\Feature\Identity;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Auth\AuthRequests;
use Tests\TestCase;

/**
 * T078 (FR-030, AS-3, AS-4, R21): per-account language and theme, stored by the API and shared by
 * every session of the account.
 */
class PreferencesTest extends TestCase
{
    use AuthRequests;

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function update(array $changes, array $headers = []): TestResponse
    {
        return $this->patchMerge('/api/v2/me/preferences', $changes, ['X-CSRF-Token' => $this->csrfToken()] + $headers);
    }

    public function test_the_defaults(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $response = $this->getJson('/api/v2/me/preferences')->assertOk();

        // DEFAULT_LANG of tests/fixtures/mailcow-inc/vars.local.inc.php
        $response->assertJsonPath('data.language', 'cs-cz')->assertJsonPath('data.theme', 'system');
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));
    }

    public function test_the_tag_covers_only_the_stored_values(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $before = $this->getJson('/api/v2/me/preferences')->headers->get('ETag');

        config(['restv2.vars_overrides.DEFAULT_LANG' => 'sk-sk']);
        $response = $this->getJson('/api/v2/me/preferences');

        $response->assertJsonPath('data.language', 'sk-sk');
        $this->assertSame($before, $response->headers->get('ETag'));
    }

    public function test_updating_the_preferences(): void
    {
        $this->mailcow()->mailboxWithDomain('alice@example.org');
        $this->signInAs('alice@example.org', Role::MailboxUser);
        $etag = (string) $this->getJson('/api/v2/me/preferences')->headers->get('ETag');

        $response = $this->update(['language' => 'sk-sk', 'theme' => 'dark'], ['If-Match' => $etag])->assertOk();

        $response->assertJsonPath('data.language', 'sk-sk')->assertJsonPath('data.theme', 'dark');
        $this->assertNotSame($etag, $response->headers->get('ETag'));
        $this->assertSame($response->headers->get('ETag'), $response->json('data.etag'));

        $row = DB::table('restv2_preferences')->where('username', 'alice@example.org')->first();
        $this->assertNotNull($row);
        $this->assertSame('mailbox', $row->account_kind);
        $this->assertSame('sk-sk', $row->language);
        $this->assertSame('dark', $row->theme);

        $audit = $this->onlyAuditRecord('preferencesUpdate');
        $this->assertSame('success', $audit->outcome);
        $this->assertSame('alice@example.org', $audit->actor_username);
        $this->assertSame('preferences', $audit->target_type);
        $this->assertEqualsCanonicalizing(['language', 'theme'], $audit->details['changedFields']);
    }

    public function test_a_partial_update_keeps_the_other_value(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $this->update(['theme' => 'light'])->assertOk();

        $this->update(['language' => 'en-gb'])->assertOk()
            ->assertJsonPath('data.theme', 'light')
            ->assertJsonPath('data.language', 'en-gb');

        $records = $this->auditRecords('preferencesUpdate');
        $this->assertSame([['theme'], ['language']], array_map(fn ($r) => $r->details['changedFields'], $records));
    }

    public function test_an_unchanged_value_is_not_audited(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $this->update(['theme' => 'dark'])->assertOk();
        $etag = (string) $this->getJson('/api/v2/me/preferences')->headers->get('ETag');

        $response = $this->update(['theme' => 'dark'])->assertOk();

        $this->assertSame($etag, $response->headers->get('ETag'));
        $this->assertCount(1, $this->auditRecords('preferencesUpdate'));
    }

    public function test_a_new_session_of_the_account_sees_the_values(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $this->update(['language' => 'sk-sk', 'theme' => 'dark'])->assertOk();

        $this->forgetCookies();
        $this->signInAs('admin', Role::Admin);

        $this->getJson('/api/v2/me/preferences')->assertJsonPath('data.language', 'sk-sk')->assertJsonPath('data.theme', 'dark');
        $this->getJson('/api/v2/me')->assertJsonPath('data.preferences.language', 'sk-sk')->assertJsonPath('data.preferences.theme', 'dark');
    }

    public function test_admin_and_mailbox_accounts_of_the_same_name_are_separate(): void
    {
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->admin('both@example.org', 'x');
        $this->mailcow()->mailbox('both@example.org');
        $this->signInAs('both@example.org', Role::Admin);
        $this->update(['theme' => 'dark'])->assertOk();

        $this->forgetCookies();
        $this->signInAs('both@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/me/preferences')->assertJsonPath('data.theme', 'system');
        $this->update(['theme' => 'light'])->assertOk();
        $this->assertSame(2, DB::table('restv2_preferences')->where('username', 'both@example.org')->count());
    }

    public function test_a_language_that_is_not_enabled_is_refused(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->update(['language' => 'de-de'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.language.0.code', 'notInList')
            ->assertJsonPath('errors.language.0.params.allowed', ['cs-cz', 'en-gb', 'sk-sk']);

        $this->assertSame(0, DB::table('restv2_preferences')->count());
        $this->assertSame([], $this->auditRecords('preferencesUpdate'));
    }

    public function test_invalid_values_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->update(['theme' => 'blue'])->assertUnprocessable()->assertJsonPath('errors.theme.0.code', 'notInList')
            ->assertJsonPath('errors.theme.0.params.allowed', ['light', 'dark', 'system']);
        $this->update([])->assertUnprocessable()->assertJsonPath('errors.body.0.code', 'required');
        $this->update(['language' => null])->assertUnprocessable()->assertJsonPath('errors.language.0.code', 'invalidType');
        $this->update(['theme' => null])->assertUnprocessable()->assertJsonPath('errors.theme.0.code', 'invalidType');
        $this->update(['language' => str_repeat('x', 17)])->assertUnprocessable()->assertJsonPath('errors.language.0.code', 'tooLong');
    }

    public function test_a_stale_tag_is_refused(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $etag = (string) $this->getJson('/api/v2/me/preferences')->headers->get('ETag');
        $this->update(['theme' => 'dark'])->assertOk();

        $this->update(['theme' => 'light'], ['If-Match' => $etag])->assertStatus(412)->assertJsonPath('type', 'about:blank');

        $this->getJson('/api/v2/me/preferences')->assertJsonPath('data.theme', 'dark');
    }

    public function test_a_stored_language_that_is_no_longer_enabled_reads_as_the_default(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);
        $this->update(['language' => 'sk-sk'])->assertOk();

        config(['restv2.vars_overrides.AVAILABLE_LANGUAGES' => '{"cs-cz": "Čeština", "en-gb": "English"}']);

        $this->getJson('/api/v2/me/preferences')->assertJsonPath('data.language', 'cs-cz');
    }

    public function test_a_restricted_session_cannot_use_the_preferences(): void
    {
        $this->mailcow()->mailboxWithDomain('bob@example.org', ['force_pw_update' => '1']);
        $this->signInAs('bob@example.org', Role::MailboxUser);

        $this->getJson('/api/v2/me/preferences')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
        $this->update(['theme' => 'dark'])->assertForbidden();
    }

    public function test_the_preferences_need_a_signed_in_session(): void
    {
        $this->getJson('/api/v2/me/preferences')->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/unauthenticated');
    }

    public function test_changes_need_the_anti_forgery_token(): void
    {
        $this->mailcow()->admin('admin', 'x');
        $this->signInAs('admin', Role::Admin);

        $this->patchMerge('/api/v2/me/preferences', ['theme' => 'dark'])
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/csrf-token-mismatch');
    }
}
