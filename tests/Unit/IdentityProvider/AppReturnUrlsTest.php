<?php

namespace Tests\Unit\IdentityProvider;

use App\Services\IdentityProvider\AppReturnUrls;
use Tests\TestCase;

/**
 * T012 (research R4, FR-021): the new web app's sign-in return addresses, one per host name of the
 * server, and the one a request on a host name gets.
 */
class AppReturnUrlsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'restv2.mailcow_hostname' => 'mail.example.org',
            'restv2.additional_server_names' => ' webmail.example.org,MAIL.example.net,,bad host',
            'restv2.identity_provider.sso_return_scheme' => 'https',
            'restv2.identity_provider.sso_return_path' => '/ng/sso/return',
        ]);
    }

    public function test_one_address_per_valid_host_name_primary_first(): void
    {
        $this->assertSame([
            'https://mail.example.org/ng/sso/return',
            'https://webmail.example.org/ng/sso/return',
            'https://mail.example.net/ng/sso/return',
        ], (new AppReturnUrls)->all());
    }

    public function test_duplicates_are_listed_once(): void
    {
        config(['restv2.additional_server_names' => 'MAIL.EXAMPLE.ORG,webmail.example.org,webmail.example.org']);

        $this->assertSame([
            'https://mail.example.org/ng/sso/return',
            'https://webmail.example.org/ng/sso/return',
        ], (new AppReturnUrls)->all());
    }

    public function test_a_request_on_a_server_host_name_gets_that_host_name(): void
    {
        $urls = new AppReturnUrls;

        $this->assertSame('https://mail.example.net/ng/sso/return', $urls->forHost('Mail.Example.NET:443'));
        $this->assertSame('https://webmail.example.org/ng/sso/return', $urls->forHost('webmail.example.org'));
    }

    public function test_an_unknown_host_gets_the_primary_host_name(): void
    {
        $urls = new AppReturnUrls;

        $this->assertSame('https://mail.example.org/ng/sso/return', $urls->forHost('evil.example.com'));
        $this->assertSame('https://mail.example.org/ng/sso/return', $urls->forHost(''));
    }

    public function test_scheme_and_path_come_from_the_settings(): void
    {
        config([
            'restv2.identity_provider.sso_return_scheme' => 'http',
            'restv2.identity_provider.sso_return_path' => '/app/return',
            'restv2.additional_server_names' => '',
        ]);

        $this->assertSame(['http://mail.example.org/app/return'], (new AppReturnUrls)->all());
    }
}
