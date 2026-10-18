<?php

namespace Tests\Unit\Logs;

use App\Services\Logs\LogCursor;
use PHPUnit\Framework\TestCase;

/**
 * Feature 011 T045 (FR-026, research R12–R14): the opaque cursors of each log source.
 */
class LogCursorTest extends TestCase
{
    public function test_redis_cursors(): void
    {
        $id = LogCursor::redis(1760781600, '{"time":"1760781600"}', 2);

        $this->assertMatchesRegularExpression('/^1760781600\.[0-9a-f]{16}\.2$/', $id);
        $this->assertSame(['time' => 1760781600, 'hash' => substr(hash('sha256', '{"time":"1760781600"}'), 0, 16), 'n' => 2], LogCursor::parseRedis($id));
        $this->assertNull(LogCursor::parseRedis('1760781600.xyz.0'));
        $this->assertNull(LogCursor::parseRedis('abc'));
        $this->assertNull(LogCursor::parseRedis('1760781600.0123456789abcdef'));
    }

    public function test_table_row_cursors(): void
    {
        $this->assertSame(42, LogCursor::parseRow('42'));
        $this->assertNull(LogCursor::parseRow('0042x'));
        $this->assertNull(LogCursor::parseRow('-1'));
        $this->assertNull(LogCursor::parseRow('99999999999999999999999'));
    }

    public function test_login_cursors(): void
    {
        $id = LogCursor::login('2026-10-18 10:00:00', 'imap', '192.0.2.1', 'alice@example.org');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $id);
        $this->assertSame(['2026-10-18 10:00:00', 'imap', '192.0.2.1', 'alice@example.org'], LogCursor::parseLogin($id));
        $this->assertNull(LogCursor::parseLogin('not+base64url'));
        $this->assertNull(LogCursor::parseLogin(rtrim(strtr(base64_encode('["only one"]'), '+/', '-_'), '=')));
    }
}
