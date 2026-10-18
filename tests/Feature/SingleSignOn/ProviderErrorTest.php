<?php

namespace Tests\Feature\SingleSignOn;

use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\TestCase;

/**
 * T054 (FR-063, US2 AS-10): a provider error reported by the client cancels the single sign-on and
 * is audited with the provider's error code.
 */
class ProviderErrorTest extends TestCase
{
    use SingleSignOnTestHelpers;

    public function test_a_cancellation_at_the_provider_is_audited_and_consumes_the_state(): void
    {
        $this->mailcow()->keycloakConfig();
        $idp = FakeOidcProvider::install();
        $state = $this->startSso()['state'];

        $this->postJson(self::HOST.'/api/v2/session/single-sign-on/provider-error', ['error' => 'access_denied', 'state' => $state], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertNoContent();

        $audit = $this->signInAudit();
        $this->assertCount(1, $audit);
        $this->assertSame(['failure', 'providerReturnedError', 'singleSignOn'], [$audit[0]->outcome, $audit[0]->failure_reason, $audit[0]->auth_method]);
        $this->assertSame('access_denied', $audit[0]->details['providerError']);

        $this->completeSso($state)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-state-invalid');
        $idp->assertNothingSent();
    }

    public function test_an_invalid_state_is_refused(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->startSso();

        $this->postJson(self::HOST.'/api/v2/session/single-sign-on/provider-error', ['error' => 'access_denied', 'state' => 'forged'], ['X-CSRF-Token' => $this->csrfToken()])
            ->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-state-invalid');
    }

    public function test_invalid_provider_error_reports_are_refused(): void
    {
        $this->mailcow()->keycloakConfig();
        $this->withoutRequestValidation();
        $state = $this->startSso()['state'];

        foreach ([['error' => 'bad"code', 'state' => $state], ['error' => str_repeat('e', 65), 'state' => $state], ['error' => 'access_denied', 'state' => $state, 'error_description' => 'User cancelled']] as $body) {
            $this->postJson(self::HOST.'/api/v2/session/single-sign-on/provider-error', $body, ['X-CSRF-Token' => $this->csrfToken()])->assertUnprocessable();
        }

        $this->assertSame([], $this->signInAudit());
    }
}
