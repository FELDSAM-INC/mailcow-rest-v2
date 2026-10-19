<?php

namespace Tests\Feature\SpamPolicy;

use App\Auth\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T075 (FR-058, AS-7.1 to AS-7.4): the spam filter's action counts as the legacy
 * dashboard chart shows them (greylisted messages added to soft-rejected), with the start of the
 * counting period when the filter reports it and the time since which it has been running.
 */
class SpamFilterStatisticsTest extends TestCase
{
    use QuarantineTestHelpers;

    private const URI = '/api/v2/spam-filter-action-statistics';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
        $this->spamFilter->withStat([
            'actions' => ['reject' => 10, 'soft reject' => 2, 'greylist' => 3, 'rewrite subject' => 1, 'add header' => 4, 'no action' => 80],
            'uptime' => 3600,
            'scanned' => 100,
        ]);
    }

    public function test_an_administrator_reads_the_counts_per_action(): void
    {
        $this->asAdmin();

        $this->getJson(self::URI)->assertOk()->assertExactJson(['data' => [
            'rejected' => 10,
            'softRejected' => 5,
            'subjectRewritten' => 1,
            'movedToJunk' => 4,
            'noAction' => 80,
            'total' => 100,
            'countedSince' => null,
            'spamFilterRunningSince' => '2026-10-19T11:00:00Z',
        ]]);
        $this->spamFilter->assertCalled('/stat');
    }

    public function test_a_missing_action_counts_zero_and_a_missing_uptime_is_unknown(): void
    {
        $this->asAdmin();
        $this->spamFilter->withStat(['actions' => ['reject' => 7]]);

        $this->getJson(self::URI)->assertOk()
            ->assertJsonPath('data.rejected', 7)
            ->assertJsonPath('data.softRejected', 0)
            ->assertJsonPath('data.noAction', 0)
            ->assertJsonPath('data.total', 7)
            ->assertJsonPath('data.spamFilterRunningSince', null);
    }

    public function test_a_reported_start_time_is_the_start_of_the_counting_period(): void
    {
        $this->asAdmin();
        $this->spamFilter->withStat(['actions' => ['no action' => 1], 'uptime' => 60, 'start_time' => 1760860800]);

        $this->getJson(self::URI)->assertOk()
            ->assertJsonPath('data.countedSince', '2025-10-19T08:00:00Z')
            ->assertJsonPath('data.spamFilterRunningSince', '2026-10-19T11:59:00Z');
    }

    public function test_an_unreachable_spam_filter_is_a_temporary_failure(): void
    {
        $this->asAdmin();
        $this->spamFilter->unreachable();

        $this->getJson(self::URI)->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertJsonPath('dependency', 'spamFilter')
            ->assertHeader('Retry-After');
    }

    public function test_other_roles_are_refused(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], scheme: 'SSHA256');
        $this->signInAs('reseller', Role::DomainAdmin);
        $this->getJson(self::URI)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->forgetCookies();
        $this->asQuarantineUser('alice@example.org');
        $this->getJson(self::URI)->assertForbidden()->assertJsonPath('type', '/api/v2/problems/forbidden');

        $this->spamFilter->assertNoCalls();
    }

    public function test_an_administrators_read_only_key_reads_and_nothing_is_audited(): void
    {
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $key = $this->apiKey('admin', 'readOnly');

        $this->getJson(self::URI, ['X-API-Key' => $key['secret']])->assertOk()->assertJsonPath('data.total', 100);

        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }
}
