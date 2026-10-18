<?php

namespace Tests\Feature\Aliases;

use Illuminate\Support\Facades\DB;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T024 (FR-011–FR-013, FR-018, US1): getAlias.
 */
class GetAliasTest extends TestCase
{
    use AliasTestHelpers;

    public function test_the_full_representation_with_its_version_tag(): void
    {
        $this->mailbox('anna@example.org');
        $id = $this->mailcow()->alias('Sales@example.org', 'anna@example.org,bob@example.org', active: 2, senderAllowed: 0, internal: 1, privateComment: 'secret');
        DB::table('alias')->where('id', $id)->update(['created' => '2026-07-01 12:30:00', 'modified' => null]);
        $this->asAdmin();

        $response = $this->getJson("/api/v2/aliases/{$id}")->assertOk();

        $this->assertSame([
            'id' => $id,
            'address' => 'Sales@example.org',
            'addressUnicode' => 'Sales@example.org',
            'kind' => 'regular',
            'domain' => 'example.org',
            'domainUnicode' => 'example.org',
            'inAliasDomain' => false,
            'primaryDomain' => 'example.org',
            'primaryDomainUnicode' => 'example.org',
            'destinationType' => 'addresses',
            'destinations' => ['anna@example.org', 'bob@example.org'],
            'destinationsUnicode' => ['anna@example.org', 'bob@example.org'],
            'active' => true,
            'senderAllowed' => false,
            'groupwareVisible' => true,
            'internal' => true,
            'publicComment' => '',
            'privateComment' => 'secret',
            'createdAt' => '2026-07-01T10:30:00Z',
            'modifiedAt' => null,
            'orphaned' => false,
            'etag' => $response->headers->get('ETag'),
        ], $response->json('data'));
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_a_catch_all_alias(): void
    {
        $this->mailcow()->domain('example.org');
        $id = $this->mailcow()->alias('@example.org', 'anna@example.org');
        $this->asAdmin();

        $this->getJson("/api/v2/aliases/{$id}")->assertOk()
            ->assertJsonPath('data.kind', 'catchAll')
            ->assertJsonPath('data.addressUnicode', '@example.org');
    }

    public function test_a_mailboxs_own_row_and_unknown_ids_are_not_found(): void
    {
        $this->mailbox('anna@example.org');
        $own = (int) DB::table('alias')->where('address', 'anna@example.org')->value('id');
        $this->asAdmin();

        $this->getJson("/api/v2/aliases/{$own}")->assertNotFound()->assertJsonPath('type', 'about:blank');
        $this->getJson('/api/v2/aliases/2147483647')->assertNotFound()->assertJsonPath('type', 'about:blank');
    }
}
