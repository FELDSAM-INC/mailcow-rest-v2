<?php

namespace Tests\Feature\RecentLogins;

use App\Casts\MailcowDateTime;
use App\Clients\Geo\CountryLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Fakes\FakeCountryLookup;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T096 (feature 005 FR-061, US8 AS-1 to AS-3, AS-5, AS-6): the recent logins of a mailbox, grouped
 * as legacy groups them.
 */
class RecentLoginListTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/mailboxes/anna@example.org/recent-logins';

    private FakeCountryLookup $countries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->countries = new FakeCountryLookup;
        $this->app->instance(CountryLookup::class, $this->countries);
    }

    /** mailcow local time, `hours` ago, as the database counts it. */
    private function hoursAgo(int $hours): string
    {
        return (string) DB::scalar('SELECT NOW() - INTERVAL ? HOUR', [$hours]);
    }

    /** A login as legacy set_sasl_log() records it: one row per service, address and mailbox, replaced. */
    private function login(string $service, string $ip, int $hoursAgo, int $appPassword = 0): void
    {
        DB::statement(
            'REPLACE INTO `sasl_log` (`username`, `real_rip`, `service`, `app_password`, `datetime`) VALUES (?, ?, ?, ?, NOW() - INTERVAL ? HOUR)',
            ['anna@example.org', $ip, $service, $appPassword, $hoursAgo],
        );
    }

    public function test_logins_are_grouped_with_the_latest_time(): void
    {
        $this->asSelfServiceUser();
        $this->redis()->hSet('IP_SHORTCOUNTRY', '203.0.113.7', 'DE');
        // IMAP from one public address on three days, then a web sign-in.
        $this->login('imap', '203.0.113.7', 72);
        $this->login('imap', '203.0.113.7', 48);
        $this->login('imap', '203.0.113.7', 24);
        $this->login('WEBUI', '203.0.113.7', 30);

        $response = $this->getJson(self::URL)->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $data = $response->json('data');
        $this->assertSame(['IMAP', 'WEBUI'], array_column($data, 'service'));
        $this->assertSame(['203.0.113.7', '203.0.113.7'], array_column($data, 'clientAddress'));
        $this->assertSame([false, false], array_column($data, 'internal'));
        $this->assertSame(['DE', 'DE'], array_column($data, 'country'));
        $this->assertSame([null, null], array_column($data, 'appPassword'));
        $imap = DB::table('sasl_log')->where('service', 'imap')->value('datetime');
        $this->assertSame(MailcowDateTime::toUtcString($imap), $data[0]['lastSeenAt']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $data[0]['lastSeenAt']);
    }

    public function test_an_app_password_login_names_the_app_password(): void
    {
        $this->asSelfServiceUser();
        $phone = $this->mailcow()->appPasswd('anna@example.org', 'Phone & tablet');
        $this->login('smtp', '198.51.100.9', 26, $phone);

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.appPassword', ['id' => $phone, 'name' => 'Phone & tablet']);
    }

    public function test_a_deleted_app_password_has_no_name(): void
    {
        $this->asSelfServiceUser();
        $phone = $this->mailcow()->appPasswd('anna@example.org', 'Phone');
        $this->mailcow()->saslLogEntry('anna@example.org', 'imap', '203.0.113.7', $this->hoursAgo(2), $phone);
        DB::table('app_passwd')->where('id', $phone)->delete();

        $this->getJson(self::URL)->assertOk()->assertJsonPath('data.0.appPassword', ['id' => $phone, 'name' => null]);
    }

    public function test_web_sign_ins_are_reported_with_one_code(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->saslLogEntry('anna@example.org', 'MAILCOWUI', '203.0.113.7', $this->hoursAgo(3));
        $this->mailcow()->saslLogEntry('anna@example.org', 'WEBUI', '203.0.113.8', $this->hoursAgo(2));
        $this->mailcow()->saslLogEntry('anna@example.org', 'imap', '203.0.113.9', $this->hoursAgo(1));

        $data = $this->getJson(self::URL.'?service=webui')->assertOk()->json('data');

        $this->assertSame(['WEBUI', 'WEBUI'], array_column($data, 'service'));
        $this->assertSame(['203.0.113.8', '203.0.113.7'], array_column($data, 'clientAddress'));
        $this->assertSame(['203.0.113.9'], array_column($this->getJson(self::URL.'?service=Imap')->json('data'), 'clientAddress'));
        $this->assertSame([], $this->getJson(self::URL.'?service=pop3')->json('data'));
    }

    public function test_internal_addresses_have_no_country(): void
    {
        $this->asSelfServiceUser();
        $this->redis()->hSet('IP_SHORTCOUNTRY', '10.0.0.5', 'DE');
        $this->mailcow()->saslLogEntry('anna@example.org', 'EAS', '10.0.0.5', $this->hoursAgo(3));
        $this->mailcow()->saslLogEntry('anna@example.org', 'SOGO', '172.22.1.1', $this->hoursAgo(2));

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame([true, true], array_column($data, 'internal'));
        $this->assertSame([null, null], array_column($data, 'country'));
        $this->assertSame([], $this->countries->calls);
    }

    public function test_the_period(): void
    {
        $this->asSelfServiceUser();
        $this->mailcow()->saslLogEntry('anna@example.org', 'imap', '203.0.113.7', $this->hoursAgo(8 * 24));
        $this->mailcow()->saslLogEntry('anna@example.org', 'pop3', '203.0.113.7', $this->hoursAgo(6 * 24));
        $this->mailcow()->saslLogEntry('anna@example.org', 'smtp', '203.0.113.7', $this->hoursAgo(31 * 24 + 1));

        $this->assertSame(['POP3'], array_column($this->getJson(self::URL)->json('data'), 'service'));
        $this->assertSame(['POP3', 'IMAP'], array_column($this->getJson(self::URL.'?days=31')->json('data'), 'service'));
        $this->assertSame([], $this->getJson(self::URL.'?days=1')->json('data'));
    }

    public function test_invalid_periods_are_refused(): void
    {
        $this->asSelfServiceUser();
        $this->withoutRequestValidation();

        $this->getJson(self::URL.'?days=32')->assertStatus(422)
            ->assertJsonPath('errors.days.0.code', 'outOfRange')
            ->assertJsonPath('errors.days.0.params.max', 31);
        $this->getJson(self::URL.'?days=0')->assertStatus(422)->assertJsonPath('errors.days.0.code', 'outOfRange');
        $this->getJson(self::URL.'?days=x')->assertStatus(422)->assertJsonPath('errors.days.0.code', 'invalidType');
        $this->getJson(self::URL.'?service='.str_repeat('a', 33))->assertStatus(422)->assertJsonPath('errors.service.0.code', 'tooLong');
    }

    public function test_pagination_sorting_and_search_after_grouping(): void
    {
        $this->asSelfServiceUser();

        foreach (['imap' => 1, 'pop3' => 2, 'smtp' => 3, 'sieve' => 4] as $service => $hours) {
            $this->mailcow()->saslLogEntry('anna@example.org', $service, '203.0.113.'.$hours, $this->hoursAgo($hours));
            $this->mailcow()->saslLogEntry('anna@example.org', $service, '198.51.100.'.$hours, $this->hoursAgo($hours + 10));
        }

        $page = $this->getJson(self::URL.'?limit=3&offset=3')->assertOk();
        $this->assertSame(['total' => 8, 'limit' => 3, 'offset' => 3], $page->json('meta'));
        $this->assertSame(['203.0.113.4', '198.51.100.1', '198.51.100.2'], array_column($page->json('data'), 'clientAddress'));

        $this->assertSame(['IMAP', 'IMAP', 'POP3'], array_column($this->getJson(self::URL.'?sort=service&limit=3')->json('data'), 'service'));
        $this->assertSame('198.51.100.1', $this->getJson(self::URL.'?sort=clientAddress')->json('data.0.clientAddress'));
        $this->assertSame('203.0.113.4', $this->getJson(self::URL.'?sort=clientAddress&order=desc')->json('data.0.clientAddress'));
        $this->assertSame('198.51.100.4', $this->getJson(self::URL.'?order=asc')->json('data.0.clientAddress'));
        $this->assertSame(['198.51.100.2'], array_column($this->getJson(self::URL.'?q=100.2')->json('data'), 'clientAddress'));
    }

    public function test_the_groups_equal_legacys_query(): void
    {
        $this->asSelfServiceUser();
        $phone = $this->mailcow()->appPasswd('anna@example.org', 'Phone');
        $this->mailcow()->saslLogEntry('anna@example.org', 'imap', '203.0.113.7', $this->hoursAgo(5), $phone);
        $this->mailcow()->saslLogEntry('anna@example.org', 'pop3', '203.0.113.7', $this->hoursAgo(4));
        $this->mailcow()->saslLogEntry('anna@example.org', 'smtp', '10.0.0.5', $this->hoursAgo(3), 999);
        $this->mailcow()->saslLogEntry('anna@example.org', 'WEBUI', '203.0.113.8', $this->hoursAgo(2));
        $this->mailcow()->saslLogEntry('anna@example.org', 'SSO', '198.51.100.1', $this->hoursAgo(200));
        $this->mailcow()->saslLogEntry('bob@example.org', 'imap', '203.0.113.7', $this->hoursAgo(1));

        // legacy last_login('get'), verbatim.
        $legacy = DB::select('SELECT `real_rip`, MAX(`datetime`) as `datetime`, `service`, `app_password`, MAX(`app_passwd`.`name`) as `app_password_name` FROM `sasl_log`
          LEFT OUTER JOIN `app_passwd` on `sasl_log`.`app_password` = `app_passwd`.`id`
          WHERE `username` = :username
            AND HOUR(TIMEDIFF(NOW(), `datetime`)) < :sasl_limit_days
              GROUP BY `real_rip`, `service`, `app_password`
              ORDER BY `datetime` DESC;', ['username' => 'anna@example.org', 'sasl_limit_days' => 7 * 24]);

        $data = $this->getJson(self::URL)->assertOk()->json('data');

        $expected = [];

        foreach ($legacy as $row) {
            $expected[] = [(string) $row->real_rip, strtoupper((string) $row->service), (int) $row->app_password ?: null, $row->app_password_name];
        }

        $this->assertSame(
            $expected,
            array_map(fn (array $entry): array => [$entry['clientAddress'], $entry['service'], $entry['appPassword']['id'] ?? null, $entry['appPassword']['name'] ?? null], $data),
        );
    }

    public function test_scope_and_callers(): void
    {
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailboxWithDomain('dora@example.net');
        $this->mailcow()->saslLogEntry('anna@example.org', 'imap', '203.0.113.7', $this->hoursAgo(1));

        $this->asDomainAdmin(['example.org'], 'reseller', ['app_passwds' => 0]);
        $this->getJson(self::URL)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v2/mailboxes/dora@example.net/recent-logins')->assertNotFound();

        $this->asSelfServiceUser('dora@example.net');
        $this->getJson(self::URL)->assertNotFound();
        $this->getJson('/api/v2/mailboxes/dora@example.net/recent-logins')->assertOk();

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->getJson(self::URL, $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk()->assertJsonPath('meta.total', 1);
    }
}
