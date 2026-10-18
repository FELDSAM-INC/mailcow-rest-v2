<?php

namespace Tests\Feature\SyncJobs;

use App\Auth\Role;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T025 (FR-001, FR-003, FR-004, US1 AS-6, AS-7): who may read sync jobs, their run
 * status and their log. A caller without the `syncjobs` flag gets `permission-flag-required` on
 * every read, even for a job of their own mailbox; `forbidden` never occurs.
 */
class SyncJobReadAccessTest extends TestCase
{
    use ApiKeys;
    use SyncJobTestHelpers;

    private int $job;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $this->syncMailbox('alice@example.org', syncjobs: false);
        $this->job = $f->syncJob('alice@example.org');
        $f->admin('admin', 'Passw0rd!', 'SSHA256');
        $f->admin('restricted', 'Passw0rd!', 'SSHA256', attributes: ['force_pw_update' => '1']);
        $f->domainAdmin('noflag', ['example.org'], ['syncjobs' => 0], scheme: 'SSHA256');
        $f->domainAdmin('reseller', ['example.org'], ['syncjobs' => 1], scheme: 'SSHA256');
    }

    /**
     * @return list<string>
     */
    private function reads(): array
    {
        return ['/api/v2/sync-jobs', '/api/v2/sync-jobs/'.$this->job, '/api/v2/sync-jobs/'.$this->job.'/log'];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function assertFlagRequired(array $headers = []): void
    {
        foreach ($this->reads() as $uri) {
            $this->getJson($uri, $headers)->assertForbidden()
                ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
                ->assertJsonPath('requiredFlags', ['syncjobs']);
        }
    }

    public function test_a_domain_administrator_without_the_flag_is_refused(): void
    {
        $this->signInAs('noflag', Role::DomainAdmin);

        $this->assertFlagRequired();
    }

    public function test_a_mailbox_user_without_the_flag_is_refused_even_for_their_own_job(): void
    {
        $this->signInAs('alice@example.org', Role::MailboxUser);

        $this->assertFlagRequired();
    }

    public function test_anonymous_callers_and_restricted_sessions_are_refused(): void
    {
        foreach ($this->reads() as $uri) {
            $this->getJson($uri)->assertUnauthorized()->assertJsonPath('type', '/api/v2/problems/unauthenticated');
        }

        $this->signInAs('restricted', Role::Admin);

        foreach ($this->reads() as $uri) {
            $this->getJson($uri)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/pending-action-required');
        }
    }

    public function test_administrator_keys_read_and_a_domain_administrator_key_follows_its_owner(): void
    {
        foreach (['readOnly', 'readWrite'] as $level) {
            $key = $this->keyHeader($this->apiKey('admin', $level));

            foreach ($this->reads() as $uri) {
                $this->getJson($uri, $key)->assertOk();
            }
        }

        foreach ($this->reads() as $uri) {
            $this->getJson($uri, $this->keyHeader($this->apiKey('reseller', 'readOnly')))->assertOk();
        }

        $this->assertFlagRequired($this->keyHeader($this->apiKey('noflag', 'readOnly')));
        $this->assertFlagRequired($this->keyHeader($this->apiKey('noflag', 'readWrite')));
    }
}
