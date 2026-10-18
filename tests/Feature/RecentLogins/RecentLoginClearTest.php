<?php

namespace Tests\Feature\RecentLogins;

use App\Clients\Geo\CountryLookup;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Fakes\FakeCountryLookup;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T098 (feature 005 FR-063, US8 AS-4): clearing the recent logins, and the legacy UI's sign-in
 * records for administrators and the mailbox user, as legacy last_login('reset') does.
 */
class RecentLoginClearTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/mailboxes/anna@example.org/recent-logins';

    private int $failedSignIn;

    private int $otherSignIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(CountryLookup::class, new FakeCountryLookup);
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailboxWithDomain('bob@example.org');
        $this->mailcow()->saslLogEntry('anna@example.org', 'imap', '203.0.113.7', (string) DB::scalar('SELECT NOW()'));
        $this->mailcow()->saslLogEntry('anna@example.org', 'smtp', '203.0.113.7', (string) DB::scalar('SELECT NOW() - INTERVAL 40 DAY'));
        $this->mailcow()->saslLogEntry('bob@example.org', 'imap', '203.0.113.7', (string) DB::scalar('SELECT NOW()'));
        $this->mailcow()->logsCheckLogin('anna@example.org');
        $this->mailcow()->logsCheckLogin('anna@example.org');
        $this->failedSignIn = $this->mailcow()->logsCheckLogin('anna@example.org', 'danger');
        $this->otherSignIn = $this->mailcow()->logsCheckLogin('bob@example.org');
    }

    /**
     * The IDs of the remaining `logs` rows of check_login.
     *
     * @return list<int>
     */
    private function signInRecords(): array
    {
        return DB::table('logs')->whereRaw('JSON_EXTRACT(`call`, "$[0]") = "check_login"')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    public function test_the_mailbox_user_clears_both(): void
    {
        $this->asSelfServiceUser();

        $this->deleteJson(self::URL, [], $this->csrf())->assertNoContent();

        $this->assertSame(0, DB::table('sasl_log')->where('username', 'anna@example.org')->count());
        $this->assertSame(1, DB::table('sasl_log')->where('username', 'bob@example.org')->count());
        $this->assertSame([$this->failedSignIn, $this->otherSignIn], $this->signInRecords());

        $records = $this->auditRecords('recentLoginsClear');
        $this->assertCount(1, $records);
        $this->assertSame('success', $records[0]->outcome);
        $this->assertSame('recentLogins', $records[0]->target_type);
        $this->assertSame('anna@example.org', $records[0]->target_id);
        $this->assertSame(['removedRecentLogins' => 2, 'removedSignInRecords' => 2], $records[0]->details);
    }

    public function test_a_domain_administrator_clears_only_the_recent_logins(): void
    {
        $this->asDomainAdmin(['example.org']);

        $this->deleteJson(self::URL, [], $this->csrf())->assertNoContent();

        $this->assertSame(0, DB::table('sasl_log')->where('username', 'anna@example.org')->count());
        $this->assertCount(4, $this->signInRecords());
        $this->assertSame(['removedRecentLogins' => 2, 'removedSignInRecords' => 0], $this->auditRecords('recentLoginsClear')[0]->details);
    }

    public function test_an_administrator_clears_both(): void
    {
        $this->asAdmin();

        $this->deleteJson(self::URL, [], $this->csrf())->assertNoContent();

        $this->assertSame(0, DB::table('sasl_log')->where('username', 'anna@example.org')->count());
        $this->assertSame([$this->failedSignIn, $this->otherSignIn], $this->signInRecords());
    }

    public function test_out_of_scope_and_read_only_callers(): void
    {
        $this->asDomainAdmin(['example.net']);
        $this->deleteJson(self::URL, [], $this->csrf())->assertNotFound();

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->deleteJson(self::URL, [], $this->keyHeader($this->apiKey('admin', 'readOnly')))
            ->assertStatus(403)
            ->assertJsonPath('type', '/api/v2/problems/insufficient-key-scope');
        $this->deleteJson(self::URL, [], $this->keyHeader($this->apiKey('admin', 'readWrite')))->assertNoContent();

        $this->assertSame(0, DB::table('sasl_log')->where('username', 'anna@example.org')->count());
        $this->assertSame(['failure', 'failure', 'success'], array_column($this->auditRecords('recentLoginsClear'), 'outcome'));
    }

    public function test_a_later_login_appears_again(): void
    {
        $this->asSelfServiceUser();
        $this->deleteJson(self::URL, [], $this->csrf())->assertNoContent();

        $this->mailcow()->saslLogEntry('anna@example.org', 'pop3', '198.51.100.9', (string) DB::scalar('SELECT NOW()'));

        $this->getJson(self::URL)->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.service', 'POP3');
    }
}
