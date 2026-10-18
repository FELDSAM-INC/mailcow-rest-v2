<?php

namespace Tests\Feature\Routing\BccMaps;

use Illuminate\Support\Facades\DB;
use Tests\Support\Routing\RoutingTestHelpers;
use Tests\TestCase;

/**
 * Feature 009 T069 (FR-001–FR-003): a domain administrator reaches the BCC maps of their domains and
 * of those domains' alias domains only; anything else answers `404` exactly like an unknown map.
 */
class BccMapScopeTest extends TestCase
{
    use RoutingTestHelpers;

    public function test_the_scope_of_a_domain_administrator(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('foreign.example');
        $f->aliasDomain('example.net', 'example.org');
        $f->aliasDomain('foreign.net', 'foreign.example');
        $own = $f->bccMap('@example.org', 'a@backup.net', 'example.org');
        $alias = $f->bccMap('@example.net', 'a@backup.net', 'example.net');
        $foreign = $f->bccMap('@foreign.example', 'a@backup.net', 'foreign.example');
        $foreignAlias = $f->bccMap('@foreign.net', 'a@backup.net', 'foreign.net');
        $this->asRoutingDomainAdmin(['example.org'], ['bcc_maps' => 1]);

        $this->assertSame([$own, $alias], array_column((array) $this->getJson('/api/v2/bcc-maps')->json('data'), 'id'));

        foreach ([$foreign, $foreignAlias, 99999] as $id) {
            $get = $this->getJson('/api/v2/bcc-maps/'.$id)->assertNotFound();
            $this->send('PATCH', '/api/v2/bcc-maps/'.$id, ['active' => false])->assertNotFound();
            $this->send('DELETE', '/api/v2/bcc-maps/'.$id)->assertNotFound();
            $this->assertSame('The resource does not exist.', $get->json('detail'));
        }

        $this->assertSame(1, (int) DB::table('bcc_maps')->where('id', $foreign)->value('active'));
    }

    public function test_an_inactive_assignment_removes_the_access_on_the_next_request(): void
    {
        $f = $this->mailcow();
        $f->domain('example.org');
        $id = $f->bccMap('@example.org', 'a@backup.net', 'example.org');
        $this->asRoutingDomainAdmin(['example.org'], ['bcc_maps' => 1]);
        $this->getJson('/api/v2/bcc-maps/'.$id)->assertOk();

        DB::table('domain_admins')->where('username', 'reseller')->update(['active' => 0]);

        $this->getJson('/api/v2/bcc-maps/'.$id)->assertNotFound();
        $this->getJson('/api/v2/bcc-maps')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
