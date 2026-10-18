<?php

namespace Tests\Unit\Services;

use App\Services\Domains\DomainTagSet;
use App\Support\Http\FieldViolations;
use PHPUnit\Framework\TestCase;

/**
 * Feature 002 T017 (FR-043, research D12): domain tags are a set of trimmed texts, merged without
 * regard to letter case, limited by the tagging limit unless the set only shrinks.
 */
class DomainTagSetTest extends TestCase
{
    /**
     * @param  list<mixed>  $tags
     * @param  list<string>  $current
     * @return array{list<string>, array<string, list<array{code: string, params: array<string, mixed>, message: string}>>}
     */
    private function normalize(array $tags, array $current = [], int $limit = 25): array
    {
        $violations = new FieldViolations;
        $result = DomainTagSet::normalize($tags, $current, $limit, $violations);

        return [$result, $violations->all()];
    }

    public function test_tags_are_trimmed_and_merged_keeping_the_first_spelling(): void
    {
        [$tags, $errors] = $this->normalize(['  Gold ', 'gold', ' GOLD ', 'silver']);

        $this->assertSame(['Gold', 'silver'], $tags);
        $this->assertSame([], $errors);
    }

    public function test_empty_and_long_tags_are_refused_by_position(): void
    {
        [, $errors] = $this->normalize(['ok', '   ', str_repeat('x', 256), str_repeat('y', 255)]);

        $this->assertSame('required', $errors['tags.1'][0]['code']);
        $this->assertSame('tooLong', $errors['tags.2'][0]['code']);
        $this->assertSame(['max' => 255], $errors['tags.2'][0]['params']);
        $this->assertArrayNotHasKey('tags.3', $errors);
    }

    public function test_more_tags_than_the_limit_are_refused(): void
    {
        $tags = array_map(fn (int $i): string => "t{$i}", range(1, 26));

        [, $errors] = $this->normalize($tags);

        $this->assertSame('tooMany', $errors['tags'][0]['code']);
        $this->assertSame(['max' => 25], $errors['tags'][0]['params']);
        $this->assertSame([], $this->normalize($tags, [], 30)[1]);
    }

    public function test_a_set_over_the_limit_may_only_shrink(): void
    {
        $current = array_map(fn (int $i): string => "t{$i}", range(1, 30));

        $this->assertSame([], $this->normalize(array_slice($current, 0, 28), $current)[1]);
        $this->assertSame([], $this->normalize(array_reverse(array_map('strtoupper', $current)), $current)[1]);

        $different = [...array_slice($current, 0, 29), 'new'];
        $this->assertSame('tooMany', $this->normalize($different, $current)[1]['tags'][0]['code']);
    }

    public function test_sorting_ignores_letter_case(): void
    {
        $this->assertSame(['alpha', 'Beta', 'Gamma', 'gamma'], DomainTagSet::sort(['gamma', 'Beta', 'Gamma', 'alpha']));
    }
}
