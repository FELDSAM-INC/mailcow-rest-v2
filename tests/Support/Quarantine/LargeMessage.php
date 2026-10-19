<?php

namespace Tests\Support\Quarantine;

/**
 * Quarantined message fixtures (feature 006 T007, research R22): the stored files of
 * tests/fixtures/quarantine/messages and a message one byte larger than the default content size
 * limit (10 MiB), built at runtime instead of being committed.
 */
final class LargeMessage
{
    /** The default content size limit (RESTV2_QUARANTINE_CONTENT_LIMIT). */
    public const LIMIT = 10485760;

    /** A syntactically valid message of exactly $bytes bytes (10 MiB + 1 by default). */
    public static function build(int $bytes = self::LIMIT + 1): string
    {
        $head = "From: Sender <sender@example.net>\r\nTo: alice@example.org\r\nSubject: Large message\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\n";
        $line = str_repeat('x', 998)."\r\n";
        $body = str_repeat($line, intdiv($bytes - strlen($head), strlen($line)));
        $rest = $bytes - strlen($head) - strlen($body);

        return $head.$body.str_repeat('y', $rest);
    }

    /** The stored bytes of a fixture of tests/fixtures/quarantine/messages, without `.eml`. */
    public static function fixture(string $name): string
    {
        $path = dirname(__DIR__, 2).'/fixtures/quarantine/messages/'.$name.'.eml';
        $content = file_get_contents($path);

        if ($content === false) {
            throw new \RuntimeException("No message fixture {$name}.");
        }

        return $content;
    }
}
