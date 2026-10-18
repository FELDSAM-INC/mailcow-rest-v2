<?php

namespace Tests\Unit\Dkim;

use App\Services\Dkim\DkimDnsOutcome;
use App\Services\Dkim\DkimDnsPublisher;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Feature 010 T013 (research R12), also feature 002 T049's publisher part: the fork's
 * dkim_update_in_dns() behind the asserting ISPConfig fake, with coded outcomes.
 */
class DkimDnsPublisherTest extends TestCase
{
    private const PUB = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtest';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'restv2.dkim.dns_publication' => true,
            'restv2.ispconfig.url' => 'https://ispconfig.example:8080/remote/json.php',
            'restv2.ispconfig.username' => 'remote',
            'restv2.ispconfig.password' => 'secret',
            'restv2.ispconfig.dns_server_id' => 4,
        ]);
    }

    private function publisher(): DkimDnsPublisher
    {
        return $this->app->make(DkimDnsPublisher::class);
    }

    public function test_disabled_or_incomplete_settings_are_not_configured_without_any_call(): void
    {
        config(['restv2.dkim.dns_publication' => false]);
        $result = $this->publisher()->publish('example.org', 'dkim', self::PUB);
        $this->assertSame(DkimDnsOutcome::NotConfigured, $result->outcome);
        $this->assertNull($result->recordName);

        config(['restv2.dkim.dns_publication' => true, 'restv2.ispconfig.url' => '']);
        $log = Log::spy();
        $this->assertSame(DkimDnsOutcome::NotConfigured, $this->publisher()->publish('example.org', 'dkim', self::PUB)->outcome);
        $this->assertSame(DkimDnsOutcome::NotConfigured, $this->publisher()->publish('example.net', 'dkim', self::PUB)->outcome);
        $log->shouldHaveReceived('warning')->once();

        $this->ispconfig()->assertNoCalls();
    }

    public function test_a_name_without_its_own_zone_has_no_zone(): void
    {
        $this->ispconfig()->zone('example.org.', 7, 3);

        $result = $this->publisher()->publish('mail.example.org', 'dkim', self::PUB);

        $this->assertSame(DkimDnsOutcome::NoZone, $result->outcome);
        $this->assertSame('dkim._domainkey', $result->recordName);
        $this->assertSame(['login', 'dns_zone_get', 'logout'], $this->ispconfig()->functions());
    }

    public function test_an_existing_record_is_updated_as_the_fork_does(): void
    {
        $this->ispconfig()->zone('example.org.', 7, 3);
        $id = $this->ispconfig()->record(7, 'dkim._domainkey', 'TXT', 'v=DKIM1;k=rsa;t=s;s=email;p=old');

        $result = $this->publisher()->publish('example.org', 'dkim', self::PUB);

        $this->assertSame(DkimDnsOutcome::Published, $result->outcome);
        $call = $this->ispconfig()->lastCall('dns_txt_update');
        $this->assertSame(2, $call['clientId']);
        $this->assertSame($id, $call['recordId']);
        $params = $call['params'];
        $this->assertSame(4, $params['server_id']);
        $this->assertSame(7, $params['zone']);
        $this->assertSame('dkim._domainkey', $params['name']);
        $this->assertSame('TXT', $params['type']);
        $this->assertSame('v=DKIM1;k=rsa;t=s;s=email;p='.self::PUB, $params['data']);
        $this->assertSame(7200, $params['ttl']);
        $this->assertSame('Y', $params['active']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $params['stamp']);
        $this->ispconfig()->assertRecord(7, 'dkim._domainkey', 'v=DKIM1;k=rsa;t=s;s=email;p='.self::PUB);
        $this->assertSame('logout', $this->ispconfig()->functions()[count($this->ispconfig()->functions()) - 1]);
    }

    public function test_a_fully_qualified_record_name_matches_too(): void
    {
        $this->ispconfig()->zone('example.org.', 7, 3);
        $this->ispconfig()->record(7, 'DKIM._domainkey.example.org.', 'TXT', 'old');

        $this->assertSame(DkimDnsOutcome::Published, $this->publisher()->publish('example.org', 'dkim', self::PUB)->outcome);
        $this->ispconfig()->assertCallCount('dns_txt_update', 1);
        $this->ispconfig()->assertCallCount('dns_txt_add', 0);
    }

    public function test_a_missing_record_is_added(): void
    {
        $this->ispconfig()->zone('example.org.', 7, 3);
        $this->ispconfig()->record(7, 'other._domainkey', 'TXT', 'x');
        $this->ispconfig()->record(7, 'dkim._domainkey', 'CNAME', 'x');

        $this->assertSame(DkimDnsOutcome::Published, $this->publisher()->publish('example.org', 'dkim', self::PUB)->outcome);
        $this->assertSame(2, $this->ispconfig()->lastCall('dns_txt_add')['clientId']);
        $this->ispconfig()->assertRecord(7, 'dkim._domainkey', 'v=DKIM1;k=rsa;t=s;s=email;p='.self::PUB);
    }

    public function test_duplicates_are_reduced_to_one_record(): void
    {
        $this->ispconfig()->zone('example.org.', 7, 3);
        $first = $this->ispconfig()->record(7, 'dkim._domainkey', 'TXT', 'a');
        $second = $this->ispconfig()->record(7, 'dkim._domainkey.example.org.', 'TXT', 'b');

        $this->publisher()->publish('example.org', 'dkim', self::PUB);

        $this->assertSame($first, $this->ispconfig()->lastCall('dns_txt_update')['recordId']);
        $this->assertSame($second, $this->ispconfig()->lastCall('dns_txt_delete')['recordId']);
    }

    public function test_a_selector_change_removes_the_old_records(): void
    {
        $this->ispconfig()->zone('example.org.', 7, 3);
        $old = $this->ispconfig()->record(7, 'old._domainkey', 'TXT', 'a');

        $result = $this->publisher()->publish('example.org', 'new', self::PUB, 'old');

        $this->assertSame(DkimDnsOutcome::Published, $result->outcome);
        $this->assertSame($old, $this->ispconfig()->lastCall('dns_txt_delete')['recordId']);
        $this->ispconfig()->assertRecord(7, 'new._domainkey', 'v=DKIM1;k=rsa;t=s;s=email;p='.self::PUB);
    }

    public function test_a_failed_stale_record_removal_is_reported(): void
    {
        $this->ispconfig()->zone('example.org.', 7, 3);
        $this->ispconfig()->record(7, 'old._domainkey', 'TXT', 'a');
        $this->ispconfig()->failOn('dns_txt_delete', 'remoteFault', 'locked');

        $result = $this->publisher()->publish('example.org', 'new', self::PUB, 'old');

        $this->assertSame(DkimDnsOutcome::Failed, $result->outcome);
        $this->assertSame('staleRecordRemovalFailed', $result->reasonCode);
        $this->ispconfig()->assertRecord(7, 'new._domainkey', 'v=DKIM1;k=rsa;t=s;s=email;p='.self::PUB);
    }

    public function test_removal_outcomes(): void
    {
        $this->ispconfig()->zone('example.org.', 7, 3);
        $this->ispconfig()->record(7, 'dkim._domainkey', 'TXT', 'a');

        $this->assertSame(DkimDnsOutcome::Removed, $this->publisher()->remove('example.org', 'dkim')->outcome);
        $this->assertSame(DkimDnsOutcome::NoRecordToRemove, $this->publisher()->remove('example.org', 'dkim')->outcome);
    }

    public function test_every_client_error_is_a_failure_with_its_reason(): void
    {
        $this->ispconfig()->zone('example.org.', 7, 3);

        foreach (['unreachable', 'authenticationFailed', 'remoteFault', 'invalidResponse', 'timeout'] as $reason) {
            $this->ispconfig()->failOn('login', $reason, 'detail of '.$reason);

            $result = $this->publisher()->publish('example.org', 'dkim', self::PUB);

            $this->assertSame(DkimDnsOutcome::Failed, $result->outcome, $reason);
            $this->assertSame($reason, $result->reasonCode);
            $this->assertSame('detail of '.$reason, $result->detail);
        }
    }

    public function test_the_publication_respects_its_time_limit(): void
    {
        config(['restv2.ispconfig.timeout' => 15]);
        $this->ispconfig()->zone('example.org.', 7, 3);
        $this->ispconfig()->record(7, 'dkim._domainkey', 'TXT', 'a');
        $this->ispconfig()->delay('dns_zone_get', 14.8);

        $result = $this->publisher()->publish('example.org', 'dkim', self::PUB);

        $this->assertSame(DkimDnsOutcome::Failed, $result->outcome);
        $this->assertSame('timeout', $result->reasonCode);
        $this->ispconfig()->assertCallCount('dns_txt_update', 0);
        $this->assertSame('logout', $this->ispconfig()->functions()[count($this->ispconfig()->functions()) - 1]);
    }

    public function test_the_result_has_the_contract_shape(): void
    {
        $this->ispconfig()->zone('example.org.', 7, 3);
        $this->ispconfig()->failOn('dns_rr_get_all_by_zone', 'remoteFault', 'boom');

        $this->assertSame(
            ['status' => 'failed', 'recordName' => 'dkim._domainkey', 'reason' => ['code' => 'remoteFault', 'detail' => 'boom']],
            $this->publisher()->publish('example.org', 'dkim', self::PUB)->toArray(),
        );
    }
}
