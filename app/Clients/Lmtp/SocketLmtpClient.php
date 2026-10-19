<?php

namespace App\Clients\Lmtp;

/**
 * LMTP over TCP to Dovecot's local delivery (feature 013 research R18), the target of legacy's
 * sys_mail() (`dovecot-mailcow`, port 24): the `220` greeting, `LHLO <MAILCOW_HOSTNAME>`, then per
 * message `MAIL FROM`, `RCPT TO`, `DATA`, the dot-stuffed message with CRLF line endings and the
 * final dot, and the single per-recipient reply (RFC 2033). The connection is kept for the next
 * message (after `RSET`) and reopened after any error. Each transaction shares one deadline; when
 * it passes before the final dot was sent, the transaction is abandoned without delivery.
 * 2xx = delivered, 4xx or no answer = temporary, 5xx = permanent.
 */
class SocketLmtpClient implements LmtpClient
{
    /** @var resource|null */
    private $socket = null;

    private bool $used = false;

    private float $deadline = 0.0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $heloName,
        private readonly float $deadlineSeconds,
    ) {}

    public function deliver(string $from, string $to, string $message): LmtpResult
    {
        $this->deadline = microtime(true) + $this->deadlineSeconds;

        try {
            if ($this->socket === null) {
                $this->open();
            } elseif ($this->used) {
                $this->expect($this->command('RSET'), 2);
            }

            $this->used = true;
            $mail = $this->command('MAIL FROM:<'.$from.'>');

            if ($mail[0] !== 2) {
                return $this->refused($mail);
            }

            $rcpt = $this->command('RCPT TO:<'.$to.'>');

            if ($rcpt[0] !== 2) {
                return $this->refused($rcpt);
            }

            $data = $this->command('DATA');

            if ($data[0] !== 3) {
                return $this->refused($data);
            }

            $this->write(self::dotStuff($message)."\r\n.\r\n");
            $final = $this->reply();

            return match ($final[0]) {
                2 => LmtpResult::delivered($final[1], $final[2]),
                4 => LmtpResult::temporaryFailure($final[1], $final[2]),
                default => LmtpResult::permanentFailure($final[1], $final[2]),
            };
        } catch (LmtpConnectionFailed $e) {
            $this->drop();

            return LmtpResult::connectionFailure($e->getMessage());
        }
    }

    public function close(): void
    {
        if ($this->socket !== null) {
            @fwrite($this->socket, "QUIT\r\n");
            $this->drop();
        }
    }

    /**
     * The message with CRLF line endings and every line that starts with a dot doubled.
     */
    public static function dotStuff(string $message): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", rtrim($message, "\r\n")));

        return implode("\r\n", array_map(fn (string $line): string => str_starts_with($line, '.') ? '.'.$line : $line, $lines));
    }

    private function open(): void
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $errstr, max(0.1, $this->remaining()));

        if ($socket === false) {
            throw new LmtpConnectionFailed("connection to {$this->host}:{$this->port} failed: {$errstr}");
        }

        $this->socket = $socket;
        $this->used = false;
        $this->expect($this->reply(), 2);
        $this->expect($this->command('LHLO '.$this->heloName), 2);
    }

    /**
     * @return array{int, int, string} reply class, code and text
     */
    private function command(string $line): array
    {
        $this->write($line."\r\n");

        return $this->reply();
    }

    private function write(string $bytes): void
    {
        if ($this->socket === null || $this->remaining() <= 0) {
            throw new LmtpConnectionFailed('timeout before the message was sent');
        }

        stream_set_timeout($this->socket, (int) floor($this->remaining()), (int) (fmod($this->remaining(), 1) * 1_000_000));

        if (@fwrite($this->socket, $bytes) !== strlen($bytes)) {
            throw new LmtpConnectionFailed('the connection was closed while sending');
        }
    }

    /**
     * Reads one (possibly multi-line) reply.
     *
     * @return array{int, int, string}
     */
    private function reply(): array
    {
        $text = [];

        do {
            if ($this->socket === null || $this->remaining() <= 0) {
                throw new LmtpConnectionFailed('timeout waiting for the reply');
            }

            stream_set_timeout($this->socket, (int) floor($this->remaining()), (int) (fmod($this->remaining(), 1) * 1_000_000));
            $line = fgets($this->socket, 4096);

            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);

                throw new LmtpConnectionFailed($meta['timed_out'] ? 'timeout waiting for the reply' : 'the connection was closed');
            }

            $line = rtrim($line, "\r\n");

            if (preg_match('/^(\d{3})([ -])(.*)$/', $line, $m) !== 1) {
                throw new LmtpConnectionFailed('unexpected reply: '.substr($line, 0, 200));
            }

            $text[] = $m[3];
        } while ($m[2] === '-');

        return [intdiv((int) $m[1], 100), (int) $m[1], (string) end($text)];
    }

    /**
     * @param  array{int, int, string}  $reply
     */
    private function expect(array $reply, int $class): void
    {
        if ($reply[0] !== $class) {
            throw new LmtpConnectionFailed("unexpected reply {$reply[1]} {$reply[2]}");
        }
    }

    /**
     * A refusal before DATA: the transaction is reset (or the connection dropped) for the next one.
     *
     * @param  array{int, int, string}  $reply
     */
    private function refused(array $reply): LmtpResult
    {
        return $reply[0] === 4 ? LmtpResult::temporaryFailure($reply[1], $reply[2]) : LmtpResult::permanentFailure($reply[1], $reply[2]);
    }

    private function remaining(): float
    {
        return $this->deadline - microtime(true);
    }

    private function drop(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->used = false;
    }
}
