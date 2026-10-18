<?php

namespace Tests\Feature\PasswordReset;

use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T055 (feature 005 FR-026): reset requests, token checks and completions are each limited per
 * client address, 10 in 15 minutes by default.
 */
class PasswordResetThrottleTest extends TestCase
{
    use SelfServiceTestHelpers;

    public function test_each_step_has_its_own_limit_per_client_address(): void
    {
        $this->configureResetMessage();
        $token = $this->csrfToken();
        $headers = ['X-CSRF-Token' => $token];

        foreach ([
            ['/api/v2/password-resets', ['address' => 'nobody@example.org'], 200],
            ['/api/v2/password-resets/token-check', ['token' => 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA'], 400],
            ['/api/v2/password-resets/completion', ['token' => 'AAAAAA-AAAAAA-AAAAAA-AAAAAA-AAAAAA', 'newPassword' => 'N3w!Passw0rd'], 400],
        ] as [$path, $body, $status]) {
            for ($i = 0; $i < 10; $i++) {
                $this->postJson($path, $body, $headers)->assertStatus($status);
            }

            $this->postJson($path, $body, $headers)
                ->assertStatus(429)
                ->assertHeader('Retry-After')
                ->assertJsonPath('type', '/api/v2/problems/too-many-requests');
        }

        // Another client address is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/v2/password-resets', ['address' => 'nobody@example.org'], $headers)
            ->assertOk();
    }
}
