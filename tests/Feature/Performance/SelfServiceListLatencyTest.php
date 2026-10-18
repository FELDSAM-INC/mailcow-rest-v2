<?php

namespace Tests\Feature\Performance;

use App\Auth\Role;
use App\Clients\Geo\CountryLookup;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Fakes\FakeCountryLookup;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T123 (feature 005 SC-010): 25-entry pages of app passwords, temporary aliases, Sieve filters and
 * recent logins answer within 1 s at p95 over 50 runs, with 200,000 `sasl_log` rows of 2,000
 * mailboxes; a recent-login page that needs country lookups (the fake taking 1.8 s) answers within
 * 2 s, because the lookups stop at their budget.
 *
 * As in the other latency tests, the rows are committed (counts inside an open transaction check
 * the visibility of every uncommitted row) and removed again in tearDown().
 */
#[Group('performance')]
class SelfServiceListLatencyTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const MAILBOXES = 2000;

    private const LOGINS = 200000;

    /** @var list<string> committed rows instead of one transaction per test (see the class comment) */
    protected array $connectionsToTransact = [];

    private ?string $startedAt = null;

    protected function tearDown(): void
    {
        if ($this->startedAt !== null) {
            foreach (['sasl_log' => 'username', 'app_passwd' => 'mailbox', 'spamalias' => 'goto', 'sieve_filters' => 'username', 'user_acl' => 'username'] as $table => $column) {
                DB::table($table)->where($column, 'like', '%@perf005.example')->delete();
            }

            DB::table('mailbox')->where('domain', 'perf005.example')->delete();
            DB::table('domain')->where('domain', 'perf005.example')->delete();
            DB::table('restv2_audit_events')->where('occurred_at', '>=', $this->startedAt)->delete();
            $this->redis()->del('IP_SHORTCOUNTRY');
        }

        parent::tearDown();
    }

    private function seedServer(): void
    {
        DB::statement("INSERT INTO domain (domain, description, aliases, mailboxes, defquota, maxquota, quota, backupmx, gal, active, relay_unknown_only, relay_all_recipients)
            VALUES ('perf005.example', '', 400, 10000, 1024, 10240, 10240000, 0, 1, 1, 0, 0)");
        DB::statement("INSERT INTO mailbox (username, password, name, quota, local_part, domain, kind, active)
            SELECT CONCAT('u', seq, '@perf005.example'), '{PLAIN}x', CONCAT('u', seq), 0, CONCAT('u', seq), 'perf005.example', '', 1 FROM seq_1_to_".self::MAILBOXES);
        // 100 logins per mailbox: services, addresses and app passwords spread over 31 days.
        DB::statement("INSERT INTO sasl_log (username, real_rip, service, app_password, datetime)
            SELECT CONCAT('u', seq % ".self::MAILBOXES." + 1, '@perf005.example'), CONCAT('198.51.', seq % 200, '.', seq DIV 2000 % 250),
                ELT(seq % 5 + 1, 'imap', 'smtp', 'pop3', 'sieve', 'SOGO'), seq % 3, NOW() - INTERVAL (seq % 744) HOUR
            FROM seq_1_to_".self::LOGINS);
        DB::statement("INSERT INTO app_passwd (name, mailbox, domain, password, imap_access, smtp_access, eas_access, dav_access, pop3_access, sieve_access, active)
            SELECT CONCAT('Device ', seq), 'u1@perf005.example', 'perf005.example', '{PLAIN}x', 1, 1, 1, 1, 1, 1, 1 FROM seq_1_to_200");
        DB::statement("INSERT INTO spamalias (address, goto, description, validity, permanent)
            SELECT CONCAT('alias', seq, '.perf@perf005.example'), 'u1@perf005.example', CONCAT('Shop ', seq), UNIX_TIMESTAMP() + 3600 * seq, 0 FROM seq_1_to_200");
        DB::statement("INSERT INTO sieve_filters (username, script_desc, script_name, script_data, filter_type)
            SELECT CONCAT('u', seq % 400 + 1, '@perf005.example'), CONCAT('Filter ', seq), IF(seq <= 400, 'active', 'inactive'), 'keep;', IF(seq % 2 = 0, 'prefilter', 'postfilter')
            FROM seq_1_to_2000");
        DB::table('user_acl')->insert(['username' => 'u1@perf005.example']);
    }

    /**
     * @param  list<float>  $seconds
     */
    private static function p95(array $seconds): float
    {
        sort($seconds);

        return $seconds[(int) ceil(0.95 * count($seconds)) - 1];
    }

    public function test_pages_answer_within_one_second_at_p95(): void
    {
        $this->assertSame(0, DB::table('domain')->where('domain', 'perf005.example')->count(), 'Leftovers of an earlier run.');
        $this->startedAt = now('UTC')->subSecond()->format('Y-m-d H:i:s');
        $this->seedServer();
        $countries = new FakeCountryLookup;
        $this->app->instance(CountryLookup::class, $countries);
        $cached = [];
        // Every address of the pages is cached, so no lookup runs here.
        foreach (range(0, 199) as $third) {
            foreach (range(0, 249) as $fourth) {
                $cached["198.51.{$third}.{$fourth}"] = 'DE';
            }
        }
        foreach (array_chunk($cached, 5000, true) as $chunk) {
            $this->redis()->hMSet('IP_SHORTCOUNTRY', $chunk);
        }

        $this->signInAs('admin', Role::Admin);
        $base = '/api/v2/mailboxes/u1@perf005.example';
        $cases = [
            'app passwords' => $base.'/app-passwords?limit=25',
            'temporary aliases' => $base.'/temporary-aliases?limit=25',
            'filters of all mailboxes' => '/api/v2/sieve-filters?limit=25',
            'filters of a mailbox' => '/api/v2/mailboxes/u2@perf005.example/sieve-filters?limit=25',
            'recent logins of 31 days' => $base.'/recent-logins?days=31&limit=25',
        ];

        foreach ($cases as $case => $uri) {
            $durations = [];

            for ($i = 0; $i < 50; $i++) {
                $start = hrtime(true);
                $response = $this->getJson($uri.'&offset='.(($i * 25) % 75))->assertOk();
                $durations[] = (hrtime(true) - $start) / 1e9;
            }

            $this->assertGreaterThan(0, $response->json('meta.total'), $case);
            $this->assertLessThan(1.0, self::p95($durations), sprintf('%s: p95 %.3f s', $case, self::p95($durations)));
        }

        $this->assertSame([], $countries->calls);
    }

    public function test_a_page_with_slow_lookups_answers_within_two_seconds(): void
    {
        $this->assertSame(0, DB::table('domain')->where('domain', 'perf005.example')->count(), 'Leftovers of an earlier run.');
        $this->startedAt = now('UTC')->subSecond()->format('Y-m-d H:i:s');
        $this->seedServer();
        $countries = new FakeCountryLookup;
        $this->app->instance(CountryLookup::class, $countries);
        $this->signInAs('admin', Role::Admin);

        $uri = '/api/v2/mailboxes/u1@perf005.example/recent-logins?days=31&limit=25';
        // The fake knows no country, so nothing is cached and the same page looks up again.
        $data = $this->getJson($uri)->assertOk()->json('data');
        $countries->delays = array_fill_keys(array_column($data, 'clientAddress'), 1.8);
        $countries->calls = [];

        $start = hrtime(true);
        $this->getJson($uri)->assertOk();
        $elapsed = (hrtime(true) - $start) / 1e9;

        $this->assertCount(1, $countries->calls);
        $this->assertLessThan(2.0, $elapsed, sprintf('%.3f s', $elapsed));
    }
}
