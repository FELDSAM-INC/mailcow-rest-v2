<?php

namespace Tests\Feature\RecentLogins;

use App\Clients\Geo\CountryLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fakes\FakeCountryLookup;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T097 (feature 005 FR-062, SC-010): countries from mailcow's shared cache, uncached public
 * addresses looked up within the time budget and written back.
 */
class RecentLoginCountryTest extends TestCase
{
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/mailboxes/anna@example.org/recent-logins';

    private FakeCountryLookup $countries;

    protected function setUp(): void
    {
        parent::setUp();

        $this->countries = new FakeCountryLookup;
        $this->app->instance(CountryLookup::class, $this->countries);
        $this->asSelfServiceUser();
    }

    private function login(string $service, string $ip, int $hoursAgo = 1): void
    {
        DB::statement(
            'REPLACE INTO `sasl_log` (`username`, `real_rip`, `service`, `app_password`, `datetime`) VALUES (?, ?, ?, 0, NOW() - INTERVAL ? HOUR)',
            ['anna@example.org', $ip, $service, $hoursAgo],
        );
    }

    /**
     * @return array<string, string|null> address => country
     */
    private function countries(string $query = ''): array
    {
        $data = $this->getJson(self::URL.$query)->assertOk()->json('data');

        return array_column($data, 'country', 'clientAddress');
    }

    public function test_a_cached_country_needs_no_lookup(): void
    {
        $this->redis()->hSet('IP_SHORTCOUNTRY', '203.0.113.7', 'DE');
        $this->login('imap', '203.0.113.7');

        $this->assertSame(['203.0.113.7' => 'DE'], $this->countries());
        $this->assertSame([], $this->countries->calls);
    }

    public function test_an_uncached_public_address_is_looked_up_and_cached(): void
    {
        $this->countries->countries = ['198.51.100.9' => 'CZ', '2001:db8::1' => 'AT'];
        $this->login('imap', '198.51.100.9', 1);
        $this->login('smtp', '198.51.100.9', 2);
        $this->login('pop3', '2001:db8::1', 3);
        $this->login('sieve', '192.0.2.1', 4);

        $this->assertSame(['198.51.100.9' => 'CZ', '2001:db8::1' => 'AT', '192.0.2.1' => null], $this->countries());
        // One concurrent batch per page, each address once, within the configured budget.
        $this->assertSame([[['198.51.100.9', '2001:db8::1', '192.0.2.1'], 1500]], $this->countries->calls);
        $this->assertSame('CZ', $this->redis()->hGet('IP_SHORTCOUNTRY', '198.51.100.9'));
        $this->assertSame('AT', $this->redis()->hGet('IP_SHORTCOUNTRY', '2001:db8::1'));
        $this->assertFalse($this->redis()->hExists('IP_SHORTCOUNTRY', '192.0.2.1'));

        // The second read uses the cache.
        $this->countries->calls = [];
        $this->countries->countries = [];
        $this->assertSame('CZ', $this->countries('?limit=1')['198.51.100.9']);
        $this->assertSame([], $this->countries->calls);
    }

    public function test_only_the_current_page_is_looked_up(): void
    {
        $this->login('imap', '198.51.100.1', 1);
        $this->login('imap', '198.51.100.2', 2);
        $this->login('imap', '198.51.100.3', 3);

        $this->countries('?limit=1&offset=1');

        $this->assertSame([[['198.51.100.2'], 1500]], $this->countries->calls);
    }

    public function test_a_slow_lookup_does_not_delay_the_answer(): void
    {
        $this->countries->countries = ['198.51.100.1' => 'CZ', '198.51.100.2' => 'DE'];
        $this->countries->delays = ['198.51.100.2' => 3.0];
        $this->login('imap', '198.51.100.1', 1);
        $this->login('imap', '198.51.100.2', 2);

        $started = microtime(true);
        $countries = $this->countries();
        $elapsed = microtime(true) - $started;

        $this->assertSame(['198.51.100.1' => 'CZ', '198.51.100.2' => null], $countries);
        $this->assertLessThan(2.0, $elapsed);
        $this->assertFalse($this->redis()->hExists('IP_SHORTCOUNTRY', '198.51.100.2'));
    }

    public function test_the_lookup_can_be_switched_off(): void
    {
        config(['restv2.country_lookup' => false]);
        $this->countries->countries = ['198.51.100.9' => 'CZ'];
        $this->redis()->hSet('IP_SHORTCOUNTRY', '203.0.113.7', 'DE');
        $this->login('imap', '198.51.100.9', 1);
        $this->login('imap', '203.0.113.7', 2);

        $this->assertSame(['198.51.100.9' => null, '203.0.113.7' => 'DE'], $this->countries());
        $this->assertSame([], $this->countries->calls);
    }

    public function test_without_the_shared_store_the_list_is_unavailable(): void
    {
        $this->login('imap', '198.51.100.9');
        $this->breakSharedStore();

        $this->getJson(self::URL)->assertStatus(503)
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertJsonPath('dependency', 'sharedStore');
    }
}
