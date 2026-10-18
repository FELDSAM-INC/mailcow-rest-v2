<?php

namespace Tests\Feature\Domains;

use Illuminate\Support\Facades\Log;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T049 (US2 AS-9, FR-027, research D10): the FeldHost ISPConfig publication of a new
 * domain's DKIM key, behind the asserting fake.
 */
class CreateDomainDnsPublicationTest extends TestCase
{
    use DomainTestHelpers;

    private function enable(): void
    {
        config([
            'restv2.dkim.dns_publication' => true,
            'restv2.ispconfig.url' => 'https://ispconfig.example:8080/remote/json.php',
            'restv2.ispconfig.username' => 'remote',
            'restv2.ispconfig.password' => 'secret',
            'restv2.ispconfig.dns_server_id' => 2,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function create(string $name = 'example.org', string $selector = 'dkim'): array
    {
        return (array) $this->postJson('/api/v2/domains', ['name' => $name, 'dkimSelector' => $selector, 'dkimKeySize' => 1024], $this->csrf())
            ->assertCreated()->json('data.creationReport');
    }

    public function test_a_disabled_publication_is_not_configured_without_any_call(): void
    {
        $this->asAdmin();

        $this->assertSame('notConfigured', $this->create()['dkimDnsPublication']);
        $this->ispconfig()->assertNoCalls();
    }

    public function test_an_existing_record_is_updated(): void
    {
        $this->enable();
        $this->ispconfig()->zone('example.org.', 7, 3);
        $this->ispconfig()->record(7, 'dkim._domainkey', 'TXT', 'old');
        $this->asAdmin();

        $this->assertSame('published', $this->create()['dkimDnsPublication']);

        $call = $this->ispconfig()->lastCall('dns_txt_update');
        $this->assertSame(2, $call['clientId']);
        $this->assertSame(2, $call['params']['server_id']);
        $this->assertSame(7200, $call['params']['ttl']);
        $this->assertSame('Y', $call['params']['active']);
        $this->assertSame('v=DKIM1;k=rsa;t=s;s=email;p='.$this->redis()->hGet('DKIM_PUB_KEYS', 'example.org'), $call['params']['data']);
    }

    public function test_a_fully_qualified_record_is_updated_and_a_missing_one_added(): void
    {
        $this->enable();
        $this->ispconfig()->zone('a.example.', 7, 3);
        $this->ispconfig()->record(7, 'dkim._domainkey.a.example.', 'TXT', 'old');
        $this->ispconfig()->zone('b.example.', 8, 5);
        $this->asAdmin();

        $this->assertSame('published', $this->create('a.example')['dkimDnsPublication']);
        $this->ispconfig()->assertCallCount('dns_txt_update', 1);

        $this->assertSame('published', $this->create('b.example')['dkimDnsPublication']);
        $this->assertSame(4, $this->ispconfig()->lastCall('dns_txt_add')['clientId']);
    }

    public function test_no_zone_and_failures_keep_the_domain(): void
    {
        $this->enable();
        $this->asAdmin();

        $this->assertSame('noZone', $this->create('nozone.example')['dkimDnsPublication']);

        $this->ispconfig()->zone('broken.example.', 9, 3);
        $this->ispconfig()->failOn('dns_rr_get_all_by_zone', 'remoteFault', 'database error');
        $report = $this->create('broken.example');
        $this->assertSame('failed', $report['dkimDnsPublication']);
        $this->assertSame('created', $report['dkimKey']);
        $this->assertNotEmpty($report['messages']['dkimDnsPublication']);

        $this->ispconfig()->zone('slow.example.', 10, 3);
        $this->ispconfig()->failOn('dns_rr_get_all_by_zone', 'remoteFault', 'unused');
        $this->ispconfig()->delay('dns_zone_get', 16.0);
        $this->assertSame('failed', $this->create('slow.example')['dkimDnsPublication']);

        $this->assertNotNull($this->domainRow('broken.example'));
        $this->assertNotNull($this->domainRow('slow.example'));
    }

    public function test_an_incomplete_configuration_is_not_configured_with_one_warning(): void
    {
        $this->enable();
        config(['restv2.ispconfig.url' => '']);
        $log = Log::spy();
        $this->asAdmin();

        $this->assertSame('notConfigured', $this->create('a.example')['dkimDnsPublication']);
        $this->assertSame('notConfigured', $this->create('b.example')['dkimDnsPublication']);

        $log->shouldHaveReceived('warning', fn (string $m): bool => str_contains($m, 'DKIM DNS publication'))->once();
        $this->ispconfig()->assertNoCalls();
    }

    public function test_the_feldhost_selector_is_published(): void
    {
        $this->enable();
        config(['restv2.dkim.default_selector' => 'feldhost']);
        $this->ispconfig()->zone('example.org.', 7, 3);
        $this->asAdmin();

        $report = $this->postJson('/api/v2/domains', ['name' => 'example.org', 'dkimKeySize' => 1024], $this->csrf())->assertCreated()->json('data.creationReport');

        $this->assertSame('published', $report['dkimDnsPublication']);
        $this->assertSame('feldhost._domainkey', $this->ispconfig()->lastCall('dns_txt_add')['params']['name']);
    }
}
