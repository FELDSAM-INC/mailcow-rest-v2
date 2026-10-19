<?php

namespace Tests\Feature\Webmail;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\Accounts\AccountTestHelpers;
use Tests\TestCase;

/**
 * Feature 007 T085 (AS US6-1 to US6-5, FR-054 to FR-056, research R21): with
 * ALLOW_ADMIN_EMAIL_LOGIN on, administrators and domain administrators with `loginAs` open a
 * mailbox's webmail through feature 005's hand-off; never with a key or while impersonating.
 */
class WebmailSignInOnBehalfTest extends TestCase
{
    use AccountTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->impersonationCast();
        config(['restv2.allow_admin_email_login' => true]);
    }

    private function open(string $mailbox): TestResponse
    {
        return $this->postEmpty('/api/v2/mailboxes/'.$mailbox.'/webmail-sign-in', $this->csrf());
    }

    public function test_an_administrator_opens_a_mailbox_webmail(): void
    {
        $this->signInAs('alice', Role::Admin);
        $before = $this->getJson('/api/v2/me')->json('data');

        $response = $this->open('Bob@A.example')->assertOk()
            ->assertHeader('Cache-Control', 'no-store')
            ->assertJsonPath('data.mailbox', 'bob@a.example')
            ->assertJsonPath('data.url', '/SOGo/so/');

        $this->assertNotEmpty($response->headers->getCookies());
        $this->assertTrue(DB::table('sasl_log')->where('username', 'bob@a.example')->where('service', 'SSO')->where('real_rip', '127.0.0.1')->exists());
        $this->assertSame($before['username'], $this->getJson('/api/v2/me')->json('data.username'));
        $this->assertAudit('webmailSignInOnBehalf', 'success', 'mailbox', 'bob@a.example');
        $this->assertSame(0, DB::table('restv2_audit_events')->where('event_type', 'webmailHandOff')->count());
    }

    public function test_the_setting_off_refuses_every_caller(): void
    {
        config(['restv2.allow_admin_email_login' => false]);
        $this->signInAs('alice', Role::Admin);

        $this->open('bob@a.example')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/webmail-on-behalf-disabled');
        $this->getJson('/api/v2/me')->assertJsonPath('data.webmailOnBehalfEnabled', false);
        $this->assertAudit('webmailSignInOnBehalf', 'failure', 'mailbox', 'bob@a.example', ['problemType' => '/api/v2/problems/webmail-on-behalf-disabled']);
    }

    public function test_a_domain_administrator_with_login_as_opens_mailboxes_of_its_domains(): void
    {
        $this->signInAs('reseller1', Role::DomainAdmin);

        $this->getJson('/api/v2/me')->assertJsonPath('data.webmailOnBehalfEnabled', true);
        $this->open('carol@a.example')->assertOk();
        $this->open('dave@b.example')->assertNotFound();
    }

    public function test_a_domain_administrator_without_login_as_and_a_mailbox_user_are_refused(): void
    {
        DB::table('da_acl')->where('username', 'reseller1')->update(['login_as' => 0]);
        $this->signInAs('reseller1', Role::DomainAdmin);
        $this->open('carol@a.example')->assertForbidden()->assertJsonPath('requiredFlags', ['loginAs']);

        $this->signInAs('bob@a.example', Role::MailboxUser);
        $this->open('bob@a.example')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');
        $this->assertArrayNotHasKey('webmailOnBehalfEnabled', $this->getJson('/api/v2/me')->json('data'));
    }

    public function test_without_the_groupware_feature_005s_error_answers(): void
    {
        config(['restv2.skip_sogo' => true]);
        $this->signInAs('alice', Role::Admin);

        $this->open('bob@a.example')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/groupware-disabled');
    }

    public function test_keys_are_refused(): void
    {
        $this->postEmpty('/api/v2/mailboxes/bob@a.example/webmail-sign-in', $this->keyHeader($this->apiKey('alice')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/session-required');
        $this->postEmpty('/api/v2/mailboxes/bob@a.example/webmail-sign-in', $this->keyHeader($this->apiKey('alice', 'readOnly')))->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
    }

    public function test_resources_and_ineligible_mailboxes(): void
    {
        $this->mailcow()->resource('room@a.example', 'Room');
        $this->mailcow()->mailboxWithDomain('off@a.example', active: 0);
        $this->mailcow()->mailboxWithDomain('nologin@a.example', active: 2);
        $this->mailcow()->mailboxWithDomain('user@inactive.example', domainActive: 0);
        $this->signInAs('alice', Role::Admin);

        $this->open('room@a.example')->assertNotFound();

        foreach (['off@a.example' => 'mailboxInactive', 'nologin@a.example' => 'loginDisabled', 'user@inactive.example' => 'domainInactive'] as $mailbox => $reason) {
            $this->open($mailbox)->assertUnprocessable()->assertViolation('mailbox', 'notEligible')->assertViolationParams('mailbox', ['reason' => $reason]);
        }

        $this->assertFalse(DB::table('sasl_log')->where('service', 'SSO')->exists());
    }

    public function test_no_webmail_while_impersonating(): void
    {
        $legacy = $this->redis()->keys('PHPREDIS_SESSION:*');
        $this->signInAs('alice', Role::Admin);
        $this->impersonate('domainAdmin', 'reseller1')->assertCreated();

        $this->open('carol@a.example')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/not-allowed-while-impersonating');
        $this->endImpersonation()->assertNoContent();
        $this->impersonate('mailboxUser', 'bob@a.example')->assertCreated();
        $this->open('bob@a.example')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/not-allowed-while-impersonating');

        $this->assertFalse(DB::table('sasl_log')->where('service', 'SSO')->exists());
        $this->assertEqualsCanonicalizing($legacy, $this->redis()->keys('PHPREDIS_SESSION:*'));
    }

    public function test_a_restricted_session_is_refused(): void
    {
        DB::table('admin')->where('username', 'alice')->update(['attributes' => '{"force_tfa": "0", "force_pw_update": "1"}']);
        $this->signInAs('alice', Role::Admin);

        $this->open('bob@a.example')->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
    }
}
