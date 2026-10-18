<?php

namespace Tests\Feature\Domains;

use Database\Factories\MailcowRows;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T051 (SC-002): a creation through the API leaves the rows, shared-store entries and
 * outbound calls that legacy mailbox('add', 'domain') leaves for the same input
 * (tests/fixtures/legacy/domain_add_expected.php), apart from timestamps and key material.
 */
class CreateDomainParityTest extends TestCase
{
    use DomainTestHelpers;

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function cases(): array
    {
        $cases = require __DIR__.'/../../fixtures/legacy/domain_add_expected.php';

        return array_map(fn (array $case): array => [$case], $cases);
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('cases')]
    public function test_the_api_writes_what_legacy_writes(array $case): void
    {
        $default = $this->mailcow()->defaultDomainTemplate(['key_size' => 1024]);
        $reseller = $this->mailcow()->domainTemplate('Reseller', array_merge(MailcowRows::DEFAULT_DOMAIN_TEMPLATE, [
            'max_quota_for_domain' => 51200 * 1048576,
            'rl_value' => '10',
            'rl_frame' => 'h',
            'tags' => ['gold'],
            'dkim_selector' => 'res',
            'key_size' => 1024,
        ]));
        $body = $case['api'];

        if (($body['templateId'] ?? null) === 'Reseller') {
            $body['templateId'] = $reseller;
        }

        $this->asAdmin();
        $this->postJson('/api/v2/domains', $body, $this->csrf())->assertCreated();

        $name = $case['domain']['domain'];
        $row = (array) $this->domainRow($name);
        unset($row['created'], $row['modified']);
        $this->assertEquals($case['domain'], $row);
        $this->assertSame($case['tags'], $this->tagsOf($name));
        $this->assertSame($case['domain_map'], $this->redis()->hGet('DOMAIN_MAP', $name));
        $this->assertSame($case['rl_value'], $this->redis()->hGet('RL_VALUE', $name) ?: null);
        $this->assertSame($case['dkim_selector'], $this->redis()->hGet('DKIM_SELECTORS', $name));
        $restarts = array_values(array_map(fn (array $c): string => $c['service'], array_filter($this->dockerApi()->calls(), fn (array $c): bool => $c['method'] === 'restart')));
        $this->assertSame($case['restarts'], $restarts);
        $this->assertGreaterThan(0, $default);
        $this->assertSame(1, DB::table('domain')->count());
    }
}
