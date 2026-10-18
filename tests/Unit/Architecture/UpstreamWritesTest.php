<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Feature 002 T092 (constitution IV): HTTP code never writes upstream tables (services own every
 * write and its side effects), and only AuditLogger::completeFollowUps() updates audit records.
 */
class UpstreamWritesTest extends TestCase
{
    /**
     * @return array<string, string> path => contents
     */
    private static function files(string $dir): array
    {
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../../'.$dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    public function test_http_code_never_writes_upstream_tables(): void
    {
        $patterns = [
            '/DB::table\([^)]*\)(?:->[a-zA-Z]+\([^;]*?\))*->(insert|insertGetId|update|delete|upsert|updateOrInsert|truncate)\(/s',
            '/->(save|forceDelete|push)\(\)/',
            '/::(create|insert|updateOrCreate|destroy)\(/',
            '/DB::(statement|unprepared|insert|update|delete)\(/',
        ];
        $violations = [];

        foreach (self::files('app/Http') as $path => $code) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $code, $m) === 1) {
                    $violations[] = basename($path).': '.trim($m[0]);
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function test_only_the_follow_up_completion_updates_audit_records(): void
    {
        $writers = [];

        foreach (self::files('app') as $path => $code) {
            if (preg_match_all("/table\\('restv2_audit_events'\\)[^;]*->update\\(/s", $code) > 0) {
                $writers[] = basename($path);
            }
        }

        $this->assertSame(['AuditLogger.php'], $writers);
        $logger = (string) file_get_contents(__DIR__.'/../../../app/Services/Audit/AuditLogger.php');
        $this->assertSame(1, preg_match_all("/table\\('restv2_audit_events'\\)/", $logger));
        $this->assertStringContainsString('function completeFollowUps(', $logger);
    }
}
