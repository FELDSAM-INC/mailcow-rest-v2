<?php

namespace Tests\Feature\SingleSignOn;

use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\TestCase;

/**
 * T055 (edge case "user cancels"): the client discards a pending single sign-on.
 */
class CancelSingleSignOnTest extends TestCase
{
    use SingleSignOnTestHelpers;

    public function test_a_cancelled_single_sign_on_cannot_be_completed(): void
    {
        $this->mailcow()->keycloakConfig();
        $state = $this->startSso()['state'];

        $this->deleteJson(self::HOST.'/api/v2/session/single-sign-on', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();

        $this->completeSso($state)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/sign-in-state-invalid');
    }

    public function test_cancelling_with_nothing_pending_succeeds_and_is_not_audited(): void
    {
        $this->deleteJson(self::HOST.'/api/v2/session/single-sign-on', [], ['X-CSRF-Token' => $this->csrfToken()])->assertNoContent();

        $this->assertSame([], $this->allAudit());
    }
}
