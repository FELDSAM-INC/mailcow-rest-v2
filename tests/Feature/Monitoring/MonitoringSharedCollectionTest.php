<?php

namespace Tests\Feature\Monitoring;

use App\Auth\Role;
use Tests\Support\Mailcow\MailcowFactory;
use Tests\TestCase;

/**
 * Feature 011 T024 (SC-004, FR-006, FR-010): ten polling administrators cause no more container
 * service calls than one.
 */
class MonitoringSharedCollectionTest extends TestCase
{
    public function test_ten_administrators_cause_one_collection(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->mailcow()->admin("admin{$i}", MailcowFactory::PASSWORD);
        }

        for ($i = 1; $i <= 10; $i++) {
            $this->forgetCookies();
            $this->signInAs("admin{$i}", Role::Admin);
            $this->getJson('/api/v2/host')->assertOk();
            $this->getJson('/api/v2/containers')->assertOk();
        }

        $this->dockerApi()->assertCallCount('hostStats', 1);
        $this->dockerApi()->assertCallCount('containers', 1);

        $this->travel(6)->seconds();
        $this->getJson('/api/v2/containers')->assertOk();
        $this->getJson('/api/v2/host')->assertOk();
        $this->dockerApi()->assertCallCount('containers', 2);
        $this->dockerApi()->assertCallCount('hostStats', 1);

        $this->travel(5)->seconds();
        $this->getJson('/api/v2/host')->assertOk();
        $this->dockerApi()->assertCallCount('hostStats', 2);
    }
}
