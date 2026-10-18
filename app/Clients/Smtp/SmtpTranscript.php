<?php

namespace App\Clients\Smtp;

/**
 * The transcript of a connection test (feature 009 FR-043, research R13), redacted when a line is
 * recorded so that no secret ever enters the result: the argument of every `AUTH` command, every
 * client line answering a `334` challenge and the text of every `334` challenge become
 * `[redacted]`. Message content is one line `[message: N bytes]`. Server text has control
 * characters replaced by `?`; lines are cut to 1,000 characters and the transcript to 500 lines,
 * followed by one `info` line saying so.
 */
final class SmtpTranscript
{
    public const MAX_LINES = 500;

    public const MAX_LINE_LENGTH = 1000;

    /** @var list<array{direction: string, text: string}> */
    private array $lines = [];

    private bool $truncated = false;

    public function client(string $line): void
    {
        if (preg_match('/^AUTH\s+(\S+)(\s+\S.*)?$/i', $line, $m) === 1) {
            $line = 'AUTH '.$m[1].(isset($m[2]) ? ' [redacted]' : '');
        }

        $this->add('client', $line);
    }

    /** A client line answering a login challenge: never recorded as sent. */
    public function clientSecret(): void
    {
        $this->add('client', '[redacted]');
    }

    public function server(string $line): void
    {
        if (str_starts_with($line, '334')) {
            $line = '334 [redacted]';
        }

        $this->add('server', $line);
    }

    public function info(string $text): void
    {
        $this->add('info', $text);
    }

    public function message(int $bytes): void
    {
        $this->add('client', "[message: {$bytes} bytes]");
    }

    /**
     * @return list<array{direction: string, text: string}>
     */
    public function lines(): array
    {
        return $this->truncated
            ? [...$this->lines, ['direction' => 'info', 'text' => 'The transcript was cut to '.self::MAX_LINES.' lines.']]
            : $this->lines;
    }

    private function add(string $direction, string $text): void
    {
        if (count($this->lines) >= self::MAX_LINES) {
            $this->truncated = true;

            return;
        }

        $text = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $text);

        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = (string) mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }

        $this->lines[] = ['direction' => $direction, 'text' => mb_substr($text, 0, self::MAX_LINE_LENGTH)];
    }
}
