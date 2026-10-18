<?php

namespace Tests\Feature\Routing\Transports;

use Tests\Support\Routing\LegacyRoutingMaps;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T050 (SC-002, FR-040): transports written through the API are found by mailcow's
 * own Postfix lookups of 2026-09a: the transport map, the next hop's credentials and the MX-based
 * access map.
 */
class TransportPostfixParityTest extends TestCase
{
    use RoutingTestHelpers;

    public function test_the_mail_server_routes_and_authenticates_as_the_api_stored(): void
    {
        $this->asAdmin();
        $active = (int) $this->send('POST', '/api/v2/transports', ['destination' => 'example.com', 'nextHop' => '[mx.partner.net]:25', 'username' => 'u1', 'password' => 'pa:ss', 'active' => true])->assertCreated()->json('data.id');
        $this->send('POST', '/api/v2/transports', ['destination' => 'Inactive.example', 'nextHop' => 'other.example'])->assertCreated();
        $this->send('POST', '/api/v2/transports', ['destination' => '.*\.google\.com', 'nextHop' => '[gw.example]:25', 'mxBased' => true, 'active' => true])->assertCreated();

        $this->assertSame(['smtp_via_transport_maps:[mx.partner.net]:25'], LegacyRoutingMaps::postfix('mysql_transport_maps', 'example.com'));
        $this->assertSame([], LegacyRoutingMaps::postfix('mysql_transport_maps', 'inactive.example'));
        $this->assertSame(['u1:pa:ss'], LegacyRoutingMaps::postfix('mysql_sasl_passwd_maps_transport_maps', '[mx.partner.net]:25'));
        $this->assertSame(['u1:pa:ss'], LegacyRoutingMaps::postfix('mysql_sasl_passwd_maps_transport_maps', '[MX.partner.net]:25'));
        $this->assertSame(['FILTER smtp_via_transport_maps:[gw.example]:25'], LegacyRoutingMaps::postfix('mysql_mbr_access_maps', 'MX1.GOOGLE.COM'));
        $this->assertSame([], LegacyRoutingMaps::postfix('mysql_mbr_access_maps', 'mx.example.org'));

        $this->send('PATCH', '/api/v2/transports/'.$active, ['active' => false])->assertOk();
        $this->assertSame([], LegacyRoutingMaps::postfix('mysql_transport_maps', 'example.com'));
        $this->assertSame([], LegacyRoutingMaps::postfix('mysql_sasl_passwd_maps_transport_maps', '[mx.partner.net]:25'));
    }
}
