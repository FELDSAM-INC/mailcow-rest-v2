<?php

namespace Tests\Feature\Domains;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Domains\DomainTestHelpers;
use Tests\TestCase;

/**
 * Feature 002 T062 (FR-043, FR-044, US3 AS-8, AS-9): saving tags replaces the set, within the
 * tagging limit.
 */
class UpdateDomainTagsTest extends TestCase
{
    use DomainTestHelpers;

    /**
     * @param  list<string>  $tags
     * @return TestResponse<Response>
     */
    private function saveTags(array $tags): TestResponse
    {
        return $this->patchMerge('/api/v2/domains/example.org', ['tags' => $tags], $this->csrf());
    }

    /**
     * @return list<string>
     */
    private static function tags(int $count, string $prefix = 't'): array
    {
        return array_map(fn (int $i): string => $prefix.$i, range(1, $count));
    }

    public function test_the_set_is_replaced(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domainTags('example.org', ['a', 'b']);
        $this->asAdmin();

        $this->saveTags(['b', 'c'])->assertOk()->assertJsonPath('data.tags', ['b', 'c']);
        $this->assertSame(['b', 'c'], $this->tagsOf('example.org'));

        // Removing tags is saving the set without them (legacy delete/domain/tag).
        $this->saveTags(['c'])->assertOk();
        $this->assertSame(['c'], $this->tagsOf('example.org'));
        $this->saveTags([])->assertOk();
        $this->assertSame([], $this->tagsOf('example.org'));
    }

    public function test_the_tagging_limit(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->saveTags(self::tags(26))->assertStatus(422)
            ->assertJsonPath('errors.tags.0.code', 'tooMany')
            ->assertJsonPath('errors.tags.0.params', ['max' => 25]);

        config(['restv2.vars_overrides.TAGGING_LIMIT' => '30']);
        $this->saveTags(self::tags(26))->assertOk();
        $this->assertCount(26, $this->tagsOf('example.org'));
    }

    public function test_duplicates_in_another_letter_case_are_stored_once(): void
    {
        $this->mailcow()->domain('example.org');
        $this->asAdmin();

        $this->saveTags(['Gold', 'gold', ' GOLD ', 'silver'])->assertOk()->assertJsonPath('data.tags', ['Gold', 'silver']);
        $this->assertSame(['Gold', 'silver'], $this->tagsOf('example.org'));
    }

    public function test_a_legacy_set_over_the_limit_may_only_shrink(): void
    {
        $this->mailcow()->domain('example.org');
        $this->mailcow()->domainTags('example.org', self::tags(30));
        $this->asAdmin();

        $this->saveTags([...array_slice(self::tags(30), 0, 29), 'new'])->assertStatus(422)->assertJsonPath('errors.tags.0.code', 'tooMany');
        $this->saveTags(self::tags(30))->assertOk();
        $this->assertCount(30, $this->tagsOf('example.org'));
        $this->saveTags(array_slice(self::tags(30), 0, 28))->assertOk();
        $this->assertCount(28, $this->tagsOf('example.org'));
    }
}
