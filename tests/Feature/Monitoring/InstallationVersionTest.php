<?php

namespace Tests\Feature\Monitoring;

use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T097 (FR-018): the installed mailcow version.
 */
class InstallationVersionTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_the_installed_version(): void
    {
        config(['restv2.vars_overrides.MAILCOW_GIT_VERSION' => '2026-09a', 'restv2.vars_overrides.MAILCOW_BRANCH' => 'master', 'restv2.vars_overrides.MAILCOW_GIT_COMMIT' => '0123abcd', 'restv2.vars_overrides.MAILCOW_GIT_COMMIT_DATE' => '2026-09-15 10:11:12 +0200', 'restv2.vars_overrides.MAILCOW_GIT_URL' => 'https://github.com/mailcow/mailcow-dockerized']);
        $this->asAdmin();

        $this->getJson('/api/v2/installation')
            ->assertOk()
            ->assertJsonPath('data', ['tag' => '2026-09a', 'branch' => 'master', 'commit' => '0123abcd', 'commitDate' => '2026-09-15T08:11:12Z', 'projectUrl' => 'https://github.com/mailcow/mailcow-dockerized']);
        $this->dockerApi()->assertNothingCalled();
    }

    public function test_unknown_values_are_null(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/installation')->assertOk()->assertJsonPath('data', ['tag' => null, 'branch' => null, 'commit' => null, 'commitDate' => null, 'projectUrl' => null]);
    }
}
