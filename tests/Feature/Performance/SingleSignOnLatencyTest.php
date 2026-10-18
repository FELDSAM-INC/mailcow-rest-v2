<?php

namespace Tests\Feature\Performance;

use PHPUnit\Framework\Attributes\Group;
use Tests\Support\IdentityProvider\FakeOidcProvider;
use Tests\Support\IdentityProvider\IdentityProviderFixtures;
use Tests\Support\IdentityProvider\SingleSignOnTestHelpers;
use Tests\TestCase;

/**
 * T091 (SC-003): over 20 single sign-ons (start and completion, 5 of them creating the mailbox),
 * the API's own time is below 2 seconds at p95. The provider is faked and answers at once, so the
 * measured time is the API's own.
 */
#[Group('performance')]
class SingleSignOnLatencyTest extends TestCase
{
    use SingleSignOnTestHelpers;

    private const ITERATIONS = 20;

    public function test_the_apis_own_steps_take_less_than_two_seconds_at_p95(): void
    {
        $this->staffTemplate();
        $this->mailcow()->ensureDomain('example.org');
        $this->mailcow()->keycloakConfig();
        $seconds = [];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $email = $i < 5 ? "new{$i}@example.org" : "user{$i}@example.org";

            if ($i >= 5) {
                $this->externalMailbox($email, 'keycloak');
            }

            $this->forgetCookies();
            FakeOidcProvider::install()->tokenIssued()->userInfo(IdentityProviderFixtures::userInfo($email, 'User '.$i, 'staff'));
            $csrf = $this->csrfToken();

            $start = hrtime(true);
            $started = $this->postEmpty(self::HOST.'/api/v2/session/single-sign-on', ['X-CSRF-Token' => $csrf])->assertOk();
            parse_str((string) parse_url((string) $started->json('data.authorizationUrl'), PHP_URL_QUERY), $query);
            $this->postJson(self::HOST.'/api/v2/session/single-sign-on/completion', ['code' => 'code', 'state' => (string) $query['state']], ['X-CSRF-Token' => $csrf])->assertCreated();
            $seconds[] = (hrtime(true) - $start) / 1e9;
        }

        sort($seconds);
        $p95 = $seconds[(int) ceil(0.95 * count($seconds)) - 1];
        $this->assertLessThan(2.0, $p95, sprintf('single sign-on: p95 %.3f s', $p95));
    }
}
