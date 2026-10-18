<?php

namespace Tests\Unit\Clients;

use App\Clients\PublicAddress\HttpPublicAddressLookup;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature 011 T100 (research R11): mailcow's IP check service, both families in parallel.
 */
class HttpPublicAddressLookupTest extends TestCase
{
    public function test_both_families_are_asked_with_the_timeouts(): void
    {
        $options = [];
        Http::fake(function (Request $request, array $o) use (&$options) {
            $options[$request->url()] = $o;

            return Http::response(str_contains($request->url(), 'ipv4') ? "198.51.100.7\n" : '2001:db8::7');
        });

        $this->assertSame(['ipv4' => '198.51.100.7', 'ipv6' => '2001:db8::7'], (new HttpPublicAddressLookup)->lookup());

        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://ipv4.mailcow.email');
        Http::assertSent(fn (Request $r): bool => $r->url() === 'http://ipv6.mailcow.email');
        $this->assertEquals(10, $options['http://ipv4.mailcow.email']['connect_timeout']);
        $this->assertEquals(15, $options['http://ipv6.mailcow.email']['timeout']);
    }

    public function test_invalid_or_failed_answers_are_null(): void
    {
        Http::fake([
            'ipv4.mailcow.email*' => Http::response('2001:db8::1'),
            'ipv6.mailcow.email*' => Http::response('', 502),
        ]);

        $this->assertSame(['ipv4' => null, 'ipv6' => null], (new HttpPublicAddressLookup)->lookup());
    }

    public function test_a_connection_failure_of_one_family_keeps_the_other(): void
    {
        Http::fake(fn (Request $request) => str_contains($request->url(), 'ipv6') ? Http::failedConnection('timed out') : Http::response('198.51.100.7'));

        $this->assertSame(['ipv4' => '198.51.100.7', 'ipv6' => null], (new HttpPublicAddressLookup)->lookup());
    }
}
