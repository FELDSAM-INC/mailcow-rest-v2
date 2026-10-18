<?php

namespace Tests\Feature\Routing\BccMaps;

use Tests\Support\Routing\LegacyRoutingMaps;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T072 (SC-002, FR-058): maps written through the API are found by Rspamd's bcc.php of
 * 2026-09a, for the address and for `@domain`, for the right type, only while active.
 */
class BccMapRspamdParityTest extends TestCase
{
    use RoutingTestHelpers;

    public function test_rspamd_finds_the_maps_the_api_stored(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailbox('user@example.org');
        $this->asAdmin();

        $this->send('POST', '/api/v2/bcc-maps', ['localDestination' => 'example.org', 'type' => 'recipient', 'bccDestination' => 'archive@backup.net'])->assertCreated();
        $sender = (int) $this->send('POST', '/api/v2/bcc-maps', ['localDestination' => 'user@example.org', 'type' => 'sender', 'bccDestination' => 'sent@backup.net'])->assertCreated()->json('data.id');

        $this->assertSame('archive@backup.net', LegacyRoutingMaps::bcc('rcpt', '@example.org'));
        $this->assertNull(LegacyRoutingMaps::bcc('sender', '@example.org'));
        $this->assertSame('sent@backup.net', LegacyRoutingMaps::bcc('sender', 'user@example.org'));
        $this->assertNull(LegacyRoutingMaps::bcc('rcpt', 'user@example.org'));

        $this->send('PATCH', '/api/v2/bcc-maps/'.$sender, ['active' => false])->assertOk();
        $this->assertNull(LegacyRoutingMaps::bcc('sender', 'user@example.org'));
    }
}
