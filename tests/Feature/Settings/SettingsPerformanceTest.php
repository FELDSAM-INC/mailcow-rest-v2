<?php

namespace Tests\Feature\Settings;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MassMail\MassMailTestHelpers;
use Tests\Support\Settings\UsesSharedFiles;
use Tests\TestCase;

/**
 * Feature 013 T094 (SC-013, informational budget): 100 reads of each settings resource, of a page of
 * 25 Rspamd settings rules among 10,000 and of a page of 25 mass mail recipients among 10,000 answer
 * within 1 s at the 95th percentile. Run with `composer test:performance` on an otherwise idle
 * machine; it is left out of `composer test`.
 */
#[Group('performance')]
class SettingsPerformanceTest extends TestCase
{
    use MassMailTestHelpers;
    use UsesSharedFiles;

    private const READS = 100;

    private const ROWS = 10000;

    public function test_reads_stay_within_one_second_at_the_95th_percentile(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
        $this->sharedValue('MAIN_LOGO', 'data:image/png;base64,'.base64_encode((string) file_get_contents(base_path('tests/fixtures/logos/valid/logo-300x80.png'))));

        foreach (array_chunk(range(1, self::ROWS), 1000) as $chunk) {
            DB::table('settingsmap')->insert(array_map(fn (int $i): array => ['desc' => sprintf('Rule %05d', $i), 'content' => "priority = {$i};", 'active' => $i % 2], $chunk));
        }

        $job = $this->runningJob(array_map(fn (int $i): string => sprintf('user%05d@example.org', $i), range(1, self::ROWS)), ['status' => 'cancelled', 'running_slot' => null]);

        $uris = [
            '/api/v2/settings/logos/light', '/api/v2/settings/ui-texts', '/api/v2/settings/login-page', '/api/v2/settings/app-links',
            '/api/v2/settings/brand-colors', '/api/v2/settings/ip-check', '/api/v2/password-policy', '/api/v2/settings/password-reset-notification',
            '/api/v2/settings/quota-notification', '/api/v2/domains/example.org/quota-notification-recipients', '/api/v2/settings/legacy-api-cors',
            '/api/v2/settings/global-sieve-filters', '/api/v2/settings/rspamd-regex-maps/bad_words.map', '/api/v2/capabilities/settings',
            '/api/v2/settings/rspamd-rules?limit=25&offset=5000', "/api/v2/mass-mail-jobs/{$job}/recipients?limit=25&offset=5000",
        ];
        $slow = [];

        foreach ($uris as $uri) {
            $times = [];

            for ($i = 0; $i < self::READS; $i++) {
                $start = hrtime(true);
                $this->getJson($uri)->assertOk();
                $times[] = (hrtime(true) - $start) / 1e9;
            }

            sort($times);
            $p95 = $times[(int) ceil(0.95 * count($times)) - 1];

            if ($p95 >= 1.0) {
                $slow[] = sprintf('%s: %.3f s', $uri, $p95);
            }
        }

        $this->assertSame([], $slow, 'reads over 1 s at p95');
    }
}
