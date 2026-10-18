<?php

namespace Tests\Unit\Support;

use App\Support\Http\FieldViolations;
use App\Support\Tags\TagSet;
use PHPUnit\Framework\TestCase;

/**
 * Feature 003 T013 (FR-036, research R20): tags of domains and mailboxes as a set: trimmed, 1 to 255
 * characters, merged without regard to letter case keeping the first spelling, at most the tagging
 * limit unless a set already over the limit only shrinks.
 */
class TagSetTest extends TestCase
{
    /**
     * @param  array<int, mixed>  $tags
     * @param  list<string>  $current
     * @return array{list<string>, array<string, mixed>}
     */
    private static function normalise(array $tags, array $current = [], int $limit = 25): array
    {
        $violations = new FieldViolations;
        $result = TagSet::normalise($tags, $current, $limit, $violations);

        return [$result, $violations->all()];
    }

    public function test_tags_are_trimmed_and_merged_without_regard_to_case(): void
    {
        [$tags, $errors] = self::normalise([' Sales ', 'sales', 'VIP', 'SALES']);

        $this->assertSame(['Sales', 'VIP'], $tags);
        $this->assertSame([], $errors);
    }

    public function test_empty_and_long_tags_are_refused_with_their_index(): void
    {
        [, $errors] = self::normalise(['ok', '  ', str_repeat('x', 256)]);

        $this->assertSame('required', $errors['tags.1'][0]['code']);
        $this->assertSame(['code' => 'tooLong', 'params' => ['max' => 255]], array_intersect_key($errors['tags.2'][0], ['code' => 1, 'params' => 1]));
    }

    public function test_the_limit_is_enforced(): void
    {
        [, $errors] = self::normalise(array_map(fn (int $i): string => "t{$i}", range(1, 26)));

        $this->assertSame(['code' => 'tooMany', 'params' => ['max' => 25]], array_intersect_key($errors['tags'][0], ['code' => 1, 'params' => 1]));
        $this->assertSame([], self::normalise(array_map(fn (int $i): string => "t{$i}", range(1, 25)))[1]);
    }

    public function test_a_set_over_the_limit_may_shrink_but_not_grow(): void
    {
        $current = array_map(fn (int $i): string => "t{$i}", range(1, 30));

        $this->assertSame([], self::normalise(array_slice($current, 0, 28), $current)[1]);
        $this->assertSame([], self::normalise($current, $current)[1]);
        $this->assertArrayHasKey('tags', self::normalise([...$current, 't31'], $current)[1]);
    }

    public function test_the_field_name_is_configurable(): void
    {
        $violations = new FieldViolations;

        TagSet::normalise([''], [], 25, $violations, 'values.tags');

        $this->assertTrue($violations->has('values.tags.0'));
    }

    public function test_sorting_and_equality_ignore_letter_case(): void
    {
        $this->assertSame(['alpha', 'Beta', 'gamma'], TagSet::sort(['gamma', 'Beta', 'alpha']));
        $this->assertTrue(TagSet::equal(['Sales', 'vip'], ['VIP', 'sales']));
        $this->assertFalse(TagSet::equal(['Sales'], ['Sales', 'VIP']));
    }
}
