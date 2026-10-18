<?php

namespace Tests\Unit\Audit;

use App\Audit\AuditEventType;
use PHPUnit\Framework\TestCase;

/**
 * docs/conventions.md "Audit record details", docs/api-gaps.md G3: every `details` key the code
 * may write (AuditEventType::detailKeys(), which AuditLogger enforces) is documented in
 * docs/audit-events.md, and the file documents no key the code cannot write.
 */
class AuditEventDetailsDocumentedTest extends TestCase
{
    /**
     * Event types whose operations are not implemented yet. The phase that implements them adds
     * their section and removes them here (001: T089, the API key events).
     */
    private const NOT_YET_DOCUMENTED = ['apiKeyCreation', 'apiKeyRevocation'];

    /**
     * The documented keys per event type: `## <eventType>` sections, keys from the first column of
     * their tables.
     *
     * @return array<string, list<string>>
     */
    private function documented(): array
    {
        $markdown = (string) file_get_contents(__DIR__.'/../../../docs/audit-events.md');
        $sections = [];
        $current = null;

        foreach (explode("\n", $markdown) as $line) {
            if (preg_match('/^## (\S+)\s*$/', $line, $m) === 1) {
                $current = $m[1];
                $sections[$current] = [];
            } elseif ($current !== null && preg_match('/^\| `([A-Za-z0-9]+)` \|/', $line, $m) === 1) {
                $sections[$current][] = $m[1];
            }
        }

        return $sections;
    }

    public function test_every_documented_section_is_an_event_type(): void
    {
        foreach (array_keys($this->documented()) as $section) {
            $this->assertNotNull(AuditEventType::tryFrom($section), "docs/audit-events.md documents an unknown event type {$section}.");
        }
    }

    public function test_the_code_writes_exactly_the_documented_keys(): void
    {
        $documented = $this->documented();

        foreach (AuditEventType::cases() as $type) {
            if (in_array($type->value, self::NOT_YET_DOCUMENTED, true)) {
                $this->assertArrayNotHasKey($type->value, $documented, "Remove {$type->value} from NOT_YET_DOCUMENTED.");

                continue;
            }

            $this->assertArrayHasKey($type->value, $documented, "docs/audit-events.md has no section for {$type->value}.");
            $this->assertEqualsCanonicalizing($type->detailKeys(), $documented[$type->value], "The detail keys of {$type->value} differ from docs/audit-events.md.");
        }
    }
}
