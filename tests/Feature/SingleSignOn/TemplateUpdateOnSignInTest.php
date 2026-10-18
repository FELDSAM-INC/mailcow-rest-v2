<?php

namespace Tests\Feature\SingleSignOn;

use App\Services\IdentityProvider\TemplateMarker;
use Illuminate\Support\Facades\DB;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\TestCase;

/**
 * T053 (FR-025, US2 AS-7): an existing mailbox takes its mapped template's values when the template
 * or the provider's name changed since the last sign-in.
 */
class TemplateUpdateOnSignInTest extends TestCase
{
    use SingleSignOnTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staffTemplate();
        $this->mailcow()->keycloakConfig();
        $this->externalMailbox('anna@example.org', 'keycloak', ['attribute_hash' => 'old-marker']);
    }

    private function marker(): string
    {
        return TemplateMarker::for((string) DB::table('templates')->where('template', 'Staff')->value('attributes'));
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return (array) json_decode((string) DB::table('mailbox')->where('username', 'anna@example.org')->value('attributes'), true);
    }

    public function test_a_changed_template_is_applied_before_the_session_is_established(): void
    {
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('anna@example.org', 'Anna Example', 'staff'));

        $this->completeSso($this->startSso()['state'])->assertCreated();

        $mailbox = DB::table('mailbox')->where('username', 'anna@example.org')->first();
        $this->assertNotNull($mailbox);
        $this->assertSame(['Anna Example', 2147483648], [$mailbox->name, (int) $mailbox->quota]);
        $this->assertSame(['0', '0', $this->marker()], [$this->attributes()['pop3_access'], $this->attributes()['eas_access'], $this->attributes()['attribute_hash']]);
        $this->assertSame(['staff'], DB::table('tags_mailbox')->where('username', 'anna@example.org')->pluck('tag_name')->all());
        $this->assertSame(0, (int) DB::table('user_acl')->where('username', 'anna@example.org')->value('tls_policy'));

        $update = array_values(array_filter($this->allAudit(), fn ($r) => $r->event_type === 'mailboxUpdate'));
        $this->assertCount(1, $update);
        $this->assertSame(['success', 'anna@example.org', null], [$update[0]->outcome, $update[0]->actor_username, $update[0]->actor_role]);
        $this->assertSame(['identityProviderSignIn', 'Staff'], [$update[0]->details['trigger'], $update[0]->details['template']]);
        $this->assertSame(['old' => 'anna', 'new' => 'Anna Example'], $update[0]->details['changes']['displayName']);
        $this->assertSame($this->signInAudit()[0]->request_id, $update[0]->details['signInRequestId']);
        $this->assertTrue($this->signInAudit()[0]->details['templateApplied']);
    }

    public function test_an_equal_marker_and_name_change_nothing(): void
    {
        DB::table('mailbox')->where('username', 'anna@example.org')->update(['name' => 'Anna Example', 'attributes' => DB::raw("JSON_SET(`attributes`, '$.attribute_hash', '".$this->marker()."')")]);
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('anna@example.org', 'Anna Example', 'staff'));

        $this->completeSso($this->startSso()['state'])->assertCreated();

        $this->assertSame([], array_values(array_filter($this->allAudit(), fn ($r) => $r->event_type === 'mailboxUpdate')));
        $this->assertSame(0, (int) DB::table('mailbox')->where('username', 'anna@example.org')->value('quota'));
        $this->assertFalse($this->signInAudit()[0]->details['templateApplied']);
    }

    public function test_the_default_template_never_updates(): void
    {
        $this->mailcow()->identityProviderRows(['default_template' => 'Staff']);
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('anna@example.org', 'Anna Example', 'unmapped'));

        $this->completeSso($this->startSso()['state'])->assertCreated();

        $this->assertSame(0, (int) DB::table('mailbox')->where('username', 'anna@example.org')->value('quota'));
    }

    public function test_a_missing_template_skips_the_update_and_is_audited(): void
    {
        DB::table('templates')->where('template', 'Staff')->delete();
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('anna@example.org', 'Anna Example', 'staff'));

        $this->completeSso($this->startSso()['state'])->assertCreated()->assertJsonPath('data.state', 'active');

        $update = array_values(array_filter($this->allAudit(), fn ($r) => $r->event_type === 'mailboxUpdate'));
        $this->assertSame(['failure', 'notFound', 'Staff'], [$update[0]->outcome, $update[0]->failure_reason, $update[0]->details['template']]);
    }

    public function test_a_failing_update_does_not_stop_the_sign_in(): void
    {
        // A quota beyond the domain's limit is refused by feature 003's rules.
        DB::table('domain')->where('domain', 'example.org')->update(['maxquota' => 1]);
        $this->keycloakSignIn(IdentityProviderFixtures::userInfo('anna@example.org', 'Anna Example', 'staff'));

        $this->completeSso($this->startSso()['state'])->assertCreated();

        $update = array_values(array_filter($this->allAudit(), fn ($r) => $r->event_type === 'mailboxUpdate'));
        $this->assertSame(['failure', 'validationFailed'], [$update[0]->outcome, $update[0]->failure_reason]);
        $this->assertSame(0, (int) DB::table('mailbox')->where('username', 'anna@example.org')->value('quota'));
    }
}
