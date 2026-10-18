<?php

namespace Tests\Feature\Resources;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Aliases\AliasTestHelpers;
use Tests\TestCase;

/**
 * Feature 004 T073 (FR-046, FR-047, US7): listResources and getResource.
 */
class ListResourcesTest extends TestCase
{
    use AliasTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $f = $this->mailcow();
        $f->domain('example.org');
        $f->domain('xn--bcher-kva.example');
        $f->domain('foreign.example');
        $this->mailbox('anna@example.org');
        $f->resource('Room1@example.org', 'Room One', 'location', 0);
        $f->resource('Beamer@example.org', 'Projector', 'thing', -1, 0);
        $f->resource('Team@xn--bcher-kva.example', 'Team calendar', 'group', 2);
        $f->resource('Odd@foreign.example', 'Legacy value', 'thing', -5);
        DB::table('mailbox')->where('kind', '<>', '')->update(['created' => '2026-07-01 12:00:00', 'modified' => null]);
    }

    /**
     * @return list<string>
     */
    private function addresses(string $query = ''): array
    {
        return array_column((array) $this->getJson('/api/v2/resources'.$query)->assertOk()->json('data'), 'address');
    }

    public function test_the_representation_and_booking_rules(): void
    {
        $this->asAdmin();

        $entries = collect((array) $this->getJson('/api/v2/resources')->json('data'))->keyBy('address');

        $this->assertSame([
            'address' => 'Team@xn--bcher-kva.example',
            'addressUnicode' => 'Team@bücher.example',
            'description' => 'Team calendar',
            'kind' => 'group',
            'domain' => 'xn--bcher-kva.example',
            'domainUnicode' => 'bücher.example',
            'bookingRule' => 'limited',
            'bookingLimit' => 2,
            'active' => true,
            'createdAt' => '2026-07-01T10:00:00Z',
            'modifiedAt' => null,
            'etag' => $this->getJson('/api/v2/resources/team@bücher.example')->headers->get('ETag'),
        ], $entries['Team@xn--bcher-kva.example']);
        $this->assertSame(['alwaysFree', null], [$entries['Room1@example.org']['bookingRule'], $entries['Room1@example.org']['bookingLimit']]);
        $this->assertSame('unlimited', $entries['Beamer@example.org']['bookingRule']);
        $this->assertSame('unlimited', $entries['Odd@foreign.example']['bookingRule']);
        $this->assertCount(4, $entries, 'Mailboxes are never resources.');
        $this->getJson('/api/v2/resources/anna@example.org')->assertNotFound();
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function queries(): array
    {
        return [
            'default' => ['', ['Beamer@example.org', 'Odd@foreign.example', 'Room1@example.org', 'Team@xn--bcher-kva.example']],
            'address desc' => ['?order=desc', ['Team@xn--bcher-kva.example', 'Room1@example.org', 'Odd@foreign.example', 'Beamer@example.org']],
            'description' => ['?sort=description', ['Odd@foreign.example', 'Beamer@example.org', 'Room1@example.org', 'Team@xn--bcher-kva.example']],
            'kind' => ['?sort=kind', ['Team@xn--bcher-kva.example', 'Room1@example.org', 'Beamer@example.org', 'Odd@foreign.example']],
            'domain' => ['?sort=domain', ['Beamer@example.org', 'Room1@example.org', 'Odd@foreign.example', 'Team@xn--bcher-kva.example']],
            'bookingRule' => ['?sort=bookingRule', ['Odd@foreign.example', 'Beamer@example.org', 'Room1@example.org', 'Team@xn--bcher-kva.example']],
            'active' => ['?sort=active', ['Beamer@example.org', 'Odd@foreign.example', 'Room1@example.org', 'Team@xn--bcher-kva.example']],
            'createdAt' => ['?sort=createdAt', ['Beamer@example.org', 'Odd@foreign.example', 'Room1@example.org', 'Team@xn--bcher-kva.example']],
            'modifiedAt' => ['?sort=modifiedAt&order=desc', ['Beamer@example.org', 'Odd@foreign.example', 'Room1@example.org', 'Team@xn--bcher-kva.example']],
            'domain filter' => ['?domain=EXAMPLE.org', ['Beamer@example.org', 'Room1@example.org']],
            'kind filter' => ['?kind=thing&kind=group', ['Beamer@example.org', 'Odd@foreign.example', 'Team@xn--bcher-kva.example']],
            'active filter' => ['?active=false', ['Beamer@example.org']],
            'search description' => ['?q=PROJECT', ['Beamer@example.org']],
            'search address' => ['?q=room1', ['Room1@example.org']],
            'search unicode' => ['?q=%C3%BCcher', ['Team@xn--bcher-kva.example']],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('queries')]
    public function test_filters_sorting_and_search(string $query, array $expected): void
    {
        $this->asAdmin();

        $this->assertSame($expected, $this->addresses($query));
    }

    public function test_a_domain_administrator_sees_the_resources_of_their_domains(): void
    {
        $this->asDomainAdmin(['example.org']);

        $this->assertSame(['Beamer@example.org', 'Room1@example.org'], $this->addresses());
        $this->getJson('/api/v2/resources/room1@EXAMPLE.org')->assertOk()->assertJsonPath('data.address', 'Room1@example.org');
        $this->getJson('/api/v2/resources/Odd@foreign.example')->assertNotFound();
    }
}
