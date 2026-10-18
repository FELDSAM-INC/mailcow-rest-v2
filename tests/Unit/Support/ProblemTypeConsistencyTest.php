<?php

namespace Tests\Unit\Support;

use App\Support\Http\ProblemType;
use PHPUnit\Framework\TestCase;
use Tests\Support\FeatureRegistry;

/**
 * T106 (FR-049, R27): every problem type the code can answer is documented in docs/problems.md
 * with its status, and is a value of the contract's `KnownProblemType`. Every problem type that
 * an implemented feature registers has a case. Sections of planned features need none.
 */
class ProblemTypeConsistencyTest extends TestCase
{
    /**
     * `## <name>` sections of docs/problems.md and the status each one states.
     *
     * @return array<string, int|null>
     */
    private static function documented(): array
    {
        $markdown = (string) file_get_contents(__DIR__.'/../../../docs/problems.md');
        preg_match_all('/^## ([a-z0-9-]+)\s*\R(.*?)(?=^## |\z)/ms', $markdown, $sections, PREG_SET_ORDER);
        $statuses = [];

        foreach ($sections as [, $name, $body]) {
            $statuses[$name] = preg_match('/^- \*\*Status\*\*: (\d{3})\b/m', $body, $m) === 1 ? (int) $m[1] : null;
        }

        return $statuses;
    }

    /**
     * @return list<string>
     */
    private static function knownProblemTypes(): array
    {
        $contract = json_decode((string) file_get_contents(__DIR__.'/../../../build/contract/openapi.json'), true);
        $schema = $contract['components']['schemas']['KnownProblemType'] ?? [];

        return array_values($schema['enum'] ?? array_column($schema['oneOf'] ?? [], 'const'));
    }

    public function test_every_case_is_documented_with_its_status(): void
    {
        $documented = self::documented();

        foreach (ProblemType::cases() as $type) {
            $this->assertArrayHasKey($type->value, $documented, "docs/problems.md has no section {$type->value}.");
            $this->assertSame($type->status(), $documented[$type->value], "The status of {$type->value} differs from docs/problems.md.");
        }
    }

    public function test_every_case_is_a_known_problem_type_of_the_contract(): void
    {
        $known = self::knownProblemTypes();
        $this->assertNotSame([], $known, 'KnownProblemType is missing from the bundled contract.');

        foreach (ProblemType::cases() as $type) {
            $this->assertContains($type->uri(), $known, "KnownProblemType does not list {$type->uri()}.");
        }
    }

    public function test_every_registered_problem_type_has_a_case(): void
    {
        foreach (FeatureRegistry::load()->problemTypes() as $name) {
            $this->assertNotNull(ProblemType::tryFrom($name), "No App\\Support\\Http\\ProblemType case for {$name}.");
        }
    }
}
