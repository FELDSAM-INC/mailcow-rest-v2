<?php

namespace Tests\Feature\SieveFilters;

use App\Clients\Dovecot\ActiveSieveScript;
use App\Clients\Dovecot\SieveScriptReader;
use App\Clients\Dovecot\SieveScriptUnavailable;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Fakes\FakeSieveScriptReader;
use Tests\Support\SelfService\SelfServiceTestHelpers;
use Tests\TestCase;

/**
 * T108 (feature 005 FR-053, US9 AS-5): the script the user manages in webmail, as the mail server
 * reports it.
 */
class ActiveSieveScriptTest extends TestCase
{
    use ApiKeys;
    use SelfServiceTestHelpers;

    private const URL = '/api/v2/mailboxes/anna@example.org/active-sieve-script';

    private FakeSieveScriptReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = new FakeSieveScriptReader;
        $this->app->instance(SieveScriptReader::class, $this->reader);
        $this->mailcow()->mailboxWithDomain('anna@example.org');
        $this->mailcow()->mailboxWithDomain('dora@example.net');
    }

    public function test_the_active_script(): void
    {
        $this->reader->scripts['anna@example.org'] = new ActiveSieveScript('roundcube', "require \"vacation\";\nvacation \"Away\";\n");
        $this->asSelfServiceUser();

        $this->getJson(self::URL)->assertOk()->assertExactJson(['data' => [
            'mailbox' => 'anna@example.org',
            'name' => 'roundcube',
            'script' => "require \"vacation\";\nvacation \"Away\";\n",
        ]]);
        $this->assertSame(['anna@example.org'], $this->reader->asked);
    }

    public function test_no_active_script(): void
    {
        $this->asAdmin();

        $this->getJson(self::URL)->assertOk()->assertExactJson(['data' => ['mailbox' => 'anna@example.org', 'name' => null, 'script' => null]]);
    }

    public function test_an_unreachable_mail_server(): void
    {
        $this->reader->failure = new SieveScriptUnavailable('The mail server did not answer in time.');
        $this->asAdmin();

        $this->getJson(self::URL)->assertStatus(503)
            ->assertHeader('Retry-After')
            ->assertJsonPath('type', '/api/v2/problems/temporary-failure')
            ->assertJsonPath('dependency', 'mailStore');
    }

    public function test_scope_and_keys(): void
    {
        $this->asDomainAdmin(['example.org'], 'reseller', ['filters' => 0]);
        $this->getJson(self::URL)->assertOk();
        $this->getJson('/api/v2/mailboxes/dora@example.net/active-sieve-script')->assertNotFound();

        $this->asSelfServiceUser('dora@example.net');
        $this->getJson(self::URL)->assertNotFound();

        $this->forgetCookies();
        $this->mailcow()->admin('admin', 'Passw0rd!');
        $this->getJson(self::URL, $this->keyHeader($this->apiKey('admin', 'readOnly')))->assertOk();
        $this->assertSame(['anna@example.org', 'anna@example.org'], $this->reader->asked);
    }
}
