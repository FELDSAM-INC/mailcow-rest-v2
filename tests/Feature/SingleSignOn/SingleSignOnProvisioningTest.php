<?php

namespace Tests\Feature\SingleSignOn;

use App\Services\IdentityProvider\TemplateMarker;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\TestCase;

/**
 * T051 (FR-034 to FR-040, US2 AS-2 to AS-4, SC-001): a mailbox is created on the first single
 * sign-on through feature 003's creation service.
 */
class SingleSignOnProvisioningTest extends TestCase
{
    use SingleSignOnTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staffTemplate();
        $this->mailcow()->ensureDomain('example.org');
    }

    /**
     * The stored rows of a mailbox without what differs per address and time.
     *
     * @return array<string, mixed>
     */
    private function rows(string $username): array
    {
        $mailbox = (array) DB::table('mailbox')->where('username', $username)->first();
        $attributes = (array) json_decode((string) $mailbox['attributes'], true);
        unset($attributes['passwd_update'], $attributes['attribute_hash']);
        ksort($attributes);
        $acl = (array) DB::table('user_acl')->where('username', $username)->first();
        unset($acl['username']);

        return [
            'mailbox' => array_diff_key($mailbox, array_flip(['username', 'local_part', 'name', 'attributes', 'created', 'modified'])),
            'attributes' => $attributes,
            'quota2' => array_diff_key((array) DB::table('quota2')->where('username', $username)->first(), ['username' => 1]),
            'acl' => $acl,
            'tags' => DB::table('tags_mailbox')->where('username', $username)->pluck('tag_name')->all(),
            'aliasToItself' => DB::table('alias')->where('address', $username)->value('goto') === $username,
        ];
    }

    public function test_a_mapped_template_creates_the_mailbox(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('ben@example.org', 'Ben Example', ['staff', 'other']));
        $started = $this->startSso();

        $response = $this->completeSso($started['state'])->assertCreated()->assertJsonPath('data.username', 'ben@example.org');

        $mailbox = DB::table('mailbox')->where('username', 'ben@example.org')->first();
        $this->assertNotNull($mailbox);
        $this->assertSame(['keycloak', '', 'Ben Example', 2147483648], [$mailbox->authsource, $mailbox->password, $mailbox->name, (int) $mailbox->quota]);
        $template = (string) DB::table('templates')->where('template', 'Staff')->value('attributes');
        $this->assertSame(TemplateMarker::for($template), json_decode((string) $mailbox->attributes, true)['attribute_hash']);

        // The same rows as feature 003's creation from the template, apart from the address.
        $this->asAdmin();
        $templateId = (int) DB::table('templates')->where('template', 'Staff')->value('id');
        $this->postJson('/api/v2/mailboxes', ['localPart' => 'reference', 'domain' => 'example.org', 'displayName' => 'Ben Example', 'authSource' => 'keycloak', 'templateId' => $templateId], $this->csrf())->assertCreated();
        $this->assertEquals($this->rows('reference@example.org'), $this->rows('ben@example.org'));

        $creation = array_values(array_filter($this->allAudit(), fn ($r) => $r->event_type === 'mailboxCreation' && $r->target_id === 'ben@example.org'));
        $this->assertCount(1, $creation);
        $this->assertSame(['ben@example.org', null], [$creation[0]->actor_username, $creation[0]->actor_role]);
        $this->assertSame('identityProviderSignIn', $creation[0]->details['trigger']);
        $this->assertSame('Staff', $creation[0]->details['template']);
        $signIn = $this->signInAudit()[0];
        $this->assertSame($signIn->request_id, $creation[0]->details['signInRequestId']);
        $this->assertTrue($signIn->details['mailboxCreated']);
        unset($response);
    }

    public function test_the_default_template_applies_and_without_one_no_mapping_matches(): void
    {
        $this->mailcow()->keycloakConfig(['default_template' => 'Staff']);
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('dana@example.org', 'Dana', 'student'));
        $this->completeSso($this->startSso()['state'])->assertCreated();
        $this->assertTrue(DB::table('mailbox')->where('username', 'dana@example.org')->exists());

        $this->mailcow()->identityProviderRows(['default_template' => '']);
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('erik@example.org', 'Erik'));
        $this->completeSso($this->startSso()['state'])->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');
        $failure = array_values(array_filter($this->signInAudit(), fn ($r) => $r->outcome === 'failure'));
        $this->assertSame(['noMatchingMapping', 'erik@example.org'], [$failure[0]->failure_reason, $failure[0]->actor_username]);
    }

    public function test_automatic_creation_off_fails(): void
    {
        $this->mailcow()->keycloakConfig(['login_provisioning' => '']);
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('ben@example.org', 'Ben', 'staff'));

        $this->completeSso($this->startSso()['state'])->assertUnauthorized();

        $this->assertSame('autoCreationOff', $this->signInAudit()[0]->failure_reason);
        $this->assertFalse(DB::table('mailbox')->where('username', 'ben@example.org')->exists());
    }

    /**
     * @return iterable<string, array{string, \Closure(self): mixed, string}>
     */
    public static function creationFailures(): iterable
    {
        yield 'domain not hosted' => ['ben@elsewhere.example', fn (self $t) => null, 'domainNotFound'];
        yield 'mailbox limit' => ['ben@example.org', fn (self $t) => DB::table('domain')->where('domain', 'example.org')->update(['mailboxes' => 0]), 'mailboxLimitReached'];
        yield 'address used by an alias' => ['ben@example.org', fn (self $t) => $t->mailcow()->alias('ben@example.org', 'other@example.org'), 'addressInUse'];
        yield 'mapped template deleted' => ['ben@example.org', fn (self $t) => DB::table('templates')->where('template', 'Staff')->delete(), 'templateMissing'];
    }

    /**
     * @param  \Closure(self): mixed  $setup
     */
    #[DataProvider('creationFailures')]
    public function test_a_failed_creation_fails_the_sign_in_and_leaves_no_mailbox(string $email, \Closure $setup, string $cause): void
    {
        $this->mailcow()->keycloakConfig();
        $setup($this);
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo($email, 'Ben', 'staff'));

        $this->completeSso($this->startSso()['state'])->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-failed');

        $audit = $this->signInAudit()[0];
        $this->assertSame('mailboxCreationFailed', $audit->failure_reason);
        $this->assertSame($cause, $audit->details['cause']);
        $this->assertFalse(DB::table('mailbox')->where('username', $email)->exists());
    }

    public function test_generic_oidc_creation_never_forces_a_second_factor_setup_and_an_empty_name_is_the_local_part(): void
    {
        DB::table('templates')->where('template', 'Staff')->update(['attributes' => json_encode(array_merge(self::STAFF, ['force_tfa' => 1]))]);
        $this->mailcow()->genericOidcConfig();
        FakeOidcProvider::install()->tokenIssued()->userInfo(IdentityProviderFixtures::userInfo('fay@example.org', '', 'staff'));

        $this->completeSso($this->startSso()['state'])->assertCreated()->assertJsonPath('data.state', 'active');

        $mailbox = DB::table('mailbox')->where('username', 'fay@example.org')->first();
        $this->assertNotNull($mailbox);
        $this->assertSame(['generic-oidc', 'fay', '0'], [$mailbox->authsource, $mailbox->name, json_decode((string) $mailbox->attributes, true)['force_tfa']]);
    }
}
