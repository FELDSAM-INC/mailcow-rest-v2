<?php

namespace Tests\Support\Quarantine;

final class MultipartMessage
{
    /** Nested siblings stay below mailparse's per-parent limit while exceeding a global budget. */
    public static function nested(int $groups = 20, int $attachmentsPerGroup = 20): string
    {
        $message = "From: sender@example.net\r\nMIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=outer\r\n\r\n";
        $message .= "--outer\r\nContent-Type: text/plain\r\n\r\nSynthetic message.\r\n";
        for ($group = 0; $group < $groups; $group++) {
            $message .= "--outer\r\nContent-Type: multipart/mixed; boundary=group{$group}\r\n\r\n";
            $part = "--group{$group}\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=test.bin\r\n\r\nx\r\n";
            $message .= str_repeat($part, $attachmentsPerGroup)."--group{$group}--\r\n";
        }

        return $message."--outer--\r\n";
    }
}
