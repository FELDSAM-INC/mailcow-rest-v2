<?php

namespace Tests\Feature\Domains;

use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 FR-067 (G28): every domain representation carries a summary of its footer.
 */
class DomainFooterSummaryTest extends TestCase
{
    use DomainTestHelpers;

    public function test_a_domain_without_a_footer(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->assertSame([
            'set' => false,
            'htmlBytes' => 0,
            'plainBytes' => 0,
            'skipReplies' => false,
            'excludedAddressCount' => 0,
            'excludedAliasDomainCount' => 0,
        ], $this->getJson('/api/v2/domains/example.org')->assertOk()->json('data.footer'));
    }

    public function test_sizes_and_exclusion_counts_in_the_list_and_the_single_read(): void
    {
        $this->mailcow()->domain('a.example');
        $this->mailcow()->domainFooter('a.example', '<p>€</p>', 'plain', ['x@a.example', 'y@a.example'], ['alias.example'], 1);
        $this->mailcow()->domain('b.example');
        $this->mailcow()->domainFooter('b.example', '', '', ['x@b.example']);
        $this->asAdmin();

        $entries = collect((array) $this->getJson('/api/v2/domains')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(['set' => true, 'htmlBytes' => 10, 'plainBytes' => 5, 'skipReplies' => true, 'excludedAddressCount' => 2, 'excludedAliasDomainCount' => 1], $entries['a.example']['footer']);
        $this->assertFalse($entries['b.example']['footer']['set']);
        $this->assertSame(1, $entries['b.example']['footer']['excludedAddressCount']);
        $this->assertSame($entries['a.example']['footer'], $this->getJson('/api/v2/domains/a.example')->json('data.footer'));
    }

    public function test_the_summary_is_read_only_in_a_patch(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();
        $whole = $this->getJson('/api/v2/domains/example.org')->json('data');

        $whole['maxMailboxes'] = 12;
        $this->patchMerge('/api/v2/domains/example.org', $whole, $this->csrf())->assertOk();

        $this->patchMerge('/api/v2/domains/example.org', ['footer' => ['set' => true]], $this->csrf())->assertStatus(422)
            ->assertJsonPath('errors.footer.0.code', 'immutable');
    }
}
