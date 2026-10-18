<?php

namespace App\Clients\Smtp;

use Carbon\CarbonImmutable;

/**
 * The connection test's SMTP client on PHP streams (feature 009 FR-042, FR-043, research R13),
 * with the connection behaviour of legacy's transport_check.php: the host's addresses tried in
 * order, a `220` greeting, `EHLO` (falling back to `HELO`), STARTTLS when offered without
 * verifying the certificate (never implicit TLS), a login only when a username is given (PLAIN,
 * then LOGIN, as Postfix's `smtp_sasl_mechanism_filter = plain, login`), `MAIL FROM`, `RCPT TO`
 * and a short test message with legacy's content. Every connect and read waits at most the step
 * timeout and never past the overall deadline. Secrets are redacted as lines are recorded
 * (SmtpTranscript), so they never enter the result.
 *
 * A TLS-only request (feature 010 research R19, the TLSA check) stops after the STARTTLS handshake:
 * the server must offer STARTTLS (`starttlsNotOffered` otherwise), the peer certificate is captured
 * during the handshake, and the dialogue ends with `QUIT`.
 */
class StreamSmtpProbe implements SmtpProbe
{
    /** @var resource|null */
    private $stream = null;

    private SmtpTranscript $transcript;

    private float $deadline = 0.0;

    private float $stepTimeout = 15.0;

    private ?int $lastCode = null;

    private ?string $lastText = null;

    private bool $tls = false;

    private string $login = 'notReached';

    private ?string $peerCertificate = null;

    /**
     * A finite time limit (php-fpm) is raised to 90 s for the dialogue (research R13) and restored
     * afterwards; an unlimited one (the console, the test suite) is left alone, because
     * set_time_limit() applies to the rest of the process.
     */
    public function probe(SmtpProbeRequest $request): SmtpProbeResult
    {
        $previousLimit = (int) ini_get('max_execution_time');

        if ($previousLimit > 0) {
            @set_time_limit(90);
        }

        try {
            return $this->dialogue($request);
        } finally {
            if ($previousLimit > 0) {
                @set_time_limit($previousLimit);
            }
        }
    }

    private function dialogue(SmtpProbeRequest $request): SmtpProbeResult
    {
        $this->transcript = new SmtpTranscript;
        $this->deadline = microtime(true) + max(0.1, $request->totalTimeout);
        $this->stepTimeout = max(0.1, $request->stepTimeout);
        $this->lastCode = null;
        $this->lastText = null;
        $this->tls = false;
        $this->peerCertificate = null;
        $this->login = $request->username === null || $request->username === '' ? 'notConfigured' : 'notReached';
        $step = 'connect';

        try {
            $this->connect($request);

            $step = 'greeting';
            $this->expect([220]);
            $capabilities = $this->hello($request->heloName);

            if ($request->stopAfterTls) {
                $step = 'tls';

                if (! self::offers($capabilities, 'STARTTLS')) {
                    throw new SmtpStepFailure('starttlsNotOffered', 'The server does not offer STARTTLS.');
                }

                $this->command('STARTTLS');
                $this->expect([220]);
                $this->startTls($request->host, $request->capturePeerCertificate);

                if ($request->capturePeerCertificate && $this->peerCertificate === null) {
                    throw new SmtpStepFailure('noCertificate', 'The server presented no certificate.');
                }

                $this->quit();

                return $this->result(true, 'tls', null, null);
            }

            if (self::offers($capabilities, 'STARTTLS')) {
                $step = 'tls';
                $this->command('STARTTLS');
                $this->expect([220]);
                $this->startTls($request->host);
                $capabilities = $this->hello($request->heloName, true);
            }

            if ($this->login !== 'notConfigured') {
                $step = 'login';
                $this->authenticate($capabilities, (string) $request->username, (string) $request->password);
            }

            $step = 'sender';
            $this->command('MAIL FROM:<'.$request->sender.'>');
            $this->expect([250]);

            $step = 'recipient';
            $this->command('RCPT TO:<'.$request->recipient.'>');
            $this->expect([250, 251]);

            $step = 'message';
            $this->command('DATA');
            $this->expect([354]);
            $this->sendMessage($request);
            $this->expect([250]);
            $this->quit();

            return $this->result(true, 'message', null, null);
        } catch (SmtpStepFailure $failure) {
            if ($step === 'login' && $this->login === 'notReached') {
                $this->login = 'failed';
            }

            $this->quit();

            return $this->result(false, $step, $failure->reason, $failure->getMessage());
        } finally {
            if (is_resource($this->stream)) {
                @fclose($this->stream);
            }

            $this->stream = null;
        }
    }

    private function connect(SmtpProbeRequest $request): void
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => filter_var($request->host, FILTER_VALIDATE_IP) === false,
            'peer_name' => $request->host,
        ]]);
        $reason = 'connectionFailed';
        $message = 'No address to connect to.';

        foreach ($request->addresses as $address) {
            $timeout = $this->timeout();
            $target = str_contains($address, ':') ? "tcp://[{$address}]:{$request->port}" : "tcp://{$address}:{$request->port}";
            $started = microtime(true);
            $stream = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

            if ($stream !== false) {
                $this->stream = $stream;
                $this->transcript->info("Connected to {$address} port {$request->port}.");

                return;
            }

            $timedOut = $errno === 110 || stripos((string) $errstr, 'timed out') !== false || microtime(true) - $started >= $timeout - 0.05;
            [$reason, $message] = match (true) {
                $errno === 111 || stripos((string) $errstr, 'refused') !== false => ['connectionRefused', "The connection to {$address} port {$request->port} was refused."],
                $timedOut => ['timeout', "The connection to {$address} port {$request->port} timed out."],
                default => ['connectionFailed', "The connection to {$address} port {$request->port} failed: ".trim((string) $errstr)],
            };
            $this->transcript->info($message);

            if ($this->deadline - microtime(true) <= 0) {
                break;
            }
        }

        throw new SmtpStepFailure($reason, $message);
    }

    /**
     * `EHLO`, or `HELO` when the server refuses it; returns the advertised capabilities.
     *
     * @return list<string>
     */
    private function hello(string $name, bool $afterTls = false): array
    {
        $this->command('EHLO '.$name);
        [$code, $lines] = $this->reply();

        if ($code === 250) {
            return array_map('strtoupper', array_slice($lines, 1));
        }

        if ($code >= 500 && ! $afterTls) {
            $this->command('HELO '.$name);
            $this->expect([250]);

            return [];
        }

        throw new SmtpStepFailure('rejected', "The server answered {$code} to EHLO.");
    }

    private function startTls(string $host, bool $capturePeerCertificate = false): void
    {
        $stream = $this->open();
        stream_set_blocking($stream, true);
        stream_set_timeout($stream, (int) ceil($this->timeout()));

        if ($capturePeerCertificate) {
            stream_context_set_option($stream, 'ssl', 'capture_peer_cert', true);
        }

        $result = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        if ($result !== true) {
            throw new SmtpStepFailure('tlsFailed', "The TLS handshake with {$host} failed.");
        }

        $this->tls = true;
        $this->transcript->info('TLS started (the certificate is not verified).');

        if ($capturePeerCertificate) {
            $certificate = stream_context_get_params($stream)['options']['ssl']['peer_certificate'] ?? null;
            $this->peerCertificate = $certificate !== null && openssl_x509_export($certificate, $pem) ? (string) $pem : null;
        }
    }

    /**
     * @param  list<string>  $capabilities
     */
    private function authenticate(array $capabilities, string $username, string $password): void
    {
        $mechanisms = [];

        foreach ($capabilities as $capability) {
            if (preg_match('/^AUTH[ =](.*)$/', $capability, $m) === 1) {
                $mechanisms = [...$mechanisms, ...preg_split('/\s+/', trim($m[1])) ?: []];
            }
        }

        if ($mechanisms === []) {
            throw new SmtpStepFailure('loginNotOffered', 'A username is stored, but the server offers no login.');
        }

        if (in_array('PLAIN', $mechanisms, true)) {
            $this->command('AUTH PLAIN '.base64_encode("\0".$username."\0".$password));
        } elseif (in_array('LOGIN', $mechanisms, true)) {
            $this->command('AUTH LOGIN');
            $this->expect([334]);
            $this->secret(base64_encode($username));
            $this->expect([334]);
            $this->secret(base64_encode($password));
        } else {
            throw new SmtpStepFailure('loginMechanismNotSupported', 'The server offers neither PLAIN nor LOGIN.');
        }

        [$code] = $this->reply();

        if ($code !== 235) {
            $this->login = 'failed';

            throw new SmtpStepFailure('rejected', "The server refused the login ({$code}).");
        }

        $this->login = 'succeeded';
    }

    private function sendMessage(SmtpProbeRequest $request): void
    {
        $date = CarbonImmutable::now('UTC')->format(DATE_RFC2822);
        $id = bin2hex(random_bytes(12)).'@'.$request->heloName;
        $lines = [
            'Date: '.$date,
            'To: Joe Null <'.$request->recipient.'>',
            'From: Mailer <'.$request->sender.'>',
            'Subject: A subject for a SMTP test',
            'Message-ID: <'.$id.'>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=us-ascii',
            '',
            'This is our test body',
        ];
        $data = '';

        foreach ($lines as $line) {
            $data .= (str_starts_with($line, '.') ? '.' : '').$line."\r\n";
        }

        $this->write($data.".\r\n");
        $this->transcript->message(strlen($data));
    }

    private function quit(): void
    {
        if (! is_resource($this->stream) || $this->deadline - microtime(true) <= 0) {
            return;
        }

        // QUIT is best effort and not a step: its reply is recorded in the transcript only.
        [$code, $text] = [$this->lastCode, $this->lastText];

        try {
            $this->command('QUIT');
            $this->reply();
        } catch (SmtpStepFailure) {
        } finally {
            [$this->lastCode, $this->lastText] = [$code, $text];
        }
    }

    private function command(string $line): void
    {
        $this->write($line."\r\n");
        $this->transcript->client($line);
    }

    private function secret(string $line): void
    {
        $this->write($line."\r\n");
        $this->transcript->clientSecret();
    }

    private function write(string $data): void
    {
        $stream = $this->open();
        stream_set_timeout($stream, ...self::split($this->timeout()));

        while ($data !== '') {
            $written = @fwrite($stream, $data);

            if ($written === false || $written === 0) {
                throw new SmtpStepFailure('connectionClosed', 'The server closed the connection.');
            }

            $data = substr($data, $written);
        }
    }

    /**
     * @param  list<int>  $codes
     */
    private function expect(array $codes): void
    {
        [$code] = $this->reply();

        if (! in_array($code, $codes, true)) {
            throw new SmtpStepFailure('rejected', "The server answered {$code}.");
        }
    }

    /**
     * One reply, possibly multi-line: its code and the text of each line.
     *
     * @return array{int, list<string>}
     */
    private function reply(): array
    {
        $stream = $this->open();
        $lines = [];

        while (true) {
            $timeout = $this->timeout();
            stream_set_timeout($stream, ...self::split($timeout));
            $line = @fgets($stream, 8192);

            if ($line === false) {
                $meta = stream_get_meta_data($stream);

                if ($meta['timed_out']) {
                    throw new SmtpStepFailure('timeout', 'The server did not answer in time.');
                }

                throw new SmtpStepFailure('connectionClosed', 'The server closed the connection.');
            }

            $line = rtrim($line, "\r\n");
            $this->transcript->server($line);

            if (preg_match('/^([2-5][0-9]{2})([ -])(.*)$/', $line, $m) !== 1) {
                if (preg_match('/^([2-5][0-9]{2})$/', $line, $m) === 1) {
                    $m[2] = ' ';
                    $m[3] = '';
                } else {
                    throw new SmtpStepFailure('rejected', 'The server sent a malformed reply.');
                }
            }

            $lines[] = $m[3];
            $this->lastCode = (int) $m[1];
            $this->lastText = str_starts_with($line, '334') ? '[redacted]' : mb_substr((string) preg_replace('/[\x00-\x1F\x7F]/', '?', $m[3]), 0, SmtpTranscript::MAX_LINE_LENGTH);

            if ($m[2] === ' ') {
                return [(int) $m[1], $lines];
            }
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        if (! is_resource($this->stream)) {
            throw new SmtpStepFailure('connectionClosed', 'The server closed the connection.');
        }

        return $this->stream;
    }

    /** Seconds the next network operation may wait: the step timeout, never past the deadline. */
    private function timeout(): float
    {
        $left = $this->deadline - microtime(true);

        if ($left <= 0) {
            throw new SmtpStepFailure('timeout', 'The test took longer than its deadline.');
        }

        return min($this->stepTimeout, $left);
    }

    /**
     * @param  list<string>  $capabilities
     */
    private static function offers(array $capabilities, string $keyword): bool
    {
        foreach ($capabilities as $capability) {
            if ($capability === $keyword || str_starts_with($capability, $keyword.' ')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{int, int} seconds and microseconds
     */
    private static function split(float $seconds): array
    {
        $whole = (int) floor($seconds);

        return [$whole, (int) round(($seconds - $whole) * 1_000_000)];
    }

    private function result(bool $accepted, string $step, ?string $reason, ?string $message): SmtpProbeResult
    {
        return new SmtpProbeResult(
            accepted: $accepted,
            step: $step,
            failureReason: $reason,
            failureMessage: $message,
            tls: $this->tls,
            login: $this->login,
            lastReplyCode: $this->lastCode,
            lastReplyText: $this->lastText,
            transcript: $this->transcript->lines(),
            peerCertificate: $this->peerCertificate,
        );
    }
}
