<?php

namespace Tests\Feature\SingleSignOn;

use Illuminate\Support\Facades\Redis;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\TestCase;

/**
 * T057 (FR-027): signing out ends the single sign-on session here only and discards the provider's
 * tokens.
 */
class SingleSignOnSignOutTest extends TestCase
{
    use SingleSignOnTestHelpers;

    public function test_sign_out_discards_the_provider_link_without_contacting_the_provider(): void
    {
        $this->mailcow()->keycloakConfig(['mappers' => '[]', 'templates' => '[]']);
        $this->externalMailbox('anna@example.org');
        $idp = $this->keycloakSignIn(IdentityProviderFixtures::userInfo('anna@example.org'));
        $this->completeSso($this->startSso()['state'])->assertCreated();
        $this->assertCount(1, Redis::connection('mailcow')->keys('restv2:sso-link:*'));
        $sent = count($idp->sent());

        $this->deleteJson('/api/v2/session', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();

        $this->assertSame([], Redis::connection('mailcow')->keys('restv2:sso-link:*'));
        $this->assertCount($sent, $idp->sent());
        $signOut = array_values(array_filter($this->allAudit(), fn ($r) => $r->event_type === 'signOut'));
        $this->assertSame(['anna@example.org', 'singleSignOn'], [$signOut[0]->actor_username, $signOut[0]->auth_method]);
    }
}
