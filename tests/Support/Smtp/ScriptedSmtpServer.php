<?php

namespace Tests\Support\Smtp;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Starts tests/Support/Smtp/scripted-smtp-server.php (feature 009 T057) for one SMTP session, with
 * a self-signed certificate for STARTTLS generated into a temporary directory, and returns what the
 * client sent.
 */
final class ScriptedSmtpServer
{
    private readonly Process $process;

    private readonly string $directory;

    private int $port = 0;

    private ?string $certificatePem = null;

    /**
     * @param  list<array<string, mixed>>  $script
     */
    public function __construct(array $script, bool $tls = false)
    {
        $this->directory = sys_get_temp_dir().'/restv2-smtp-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
        file_put_contents($this->directory.'/script.json', json_encode($script, JSON_THROW_ON_ERROR));
        $arguments = [PHP_BINARY, __DIR__.'/scripted-smtp-server.php', $this->directory.'/script.json', $this->directory.'/received.json'];

        if ($tls) {
            $arguments[] = $this->certificate();
        }

        $this->process = new Process($arguments);
        $this->process->setTimeout(60);
        $this->process->start();

        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline) {
            $output = $this->process->getOutput();

            if (preg_match('/^(\d+)\R/', $output, $m) === 1) {
                $this->port = (int) $m[1];

                return;
            }

            if (! $this->process->isRunning()) {
                break;
            }

            usleep(20_000);
        }

        throw new RuntimeException('The scripted SMTP server did not start: '.$this->process->getErrorOutput());
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * The PEM of the self-signed certificate the server presents after STARTTLS (feature 010:
     * the TLSA digest), or null without TLS.
     */
    public function certificatePem(): ?string
    {
        return $this->certificatePem;
    }

    /**
     * Everything the client sent, after the session ended: lines, `['data' => lines]` for the
     * message data and `['rawData' => base64]` for the exact data bytes (feature 006).
     *
     * @return list<string|array{data?: list<string>, rawData?: string}>
     */
    public function received(): array
    {
        $this->process->wait();
        $json = @file_get_contents($this->directory.'/received.json');

        /** @var list<string|array{data?: list<string>, rawData?: string}> */
        return is_string($json) ? (array) json_decode($json, true) : [];
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(1);
        }

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);
    }

    private function certificate(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw new RuntimeException('No key pair for the test certificate.');
        }

        $csr = openssl_csr_new(['commonName' => 'mx.test.example'], $key, ['digest_alg' => 'sha256']);

        if ($csr === false || $csr === true) {
            throw new RuntimeException('No signing request for the test certificate.');
        }

        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);

        if ($certificate === false) {
            throw new RuntimeException('The test certificate was not signed.');
        }

        openssl_x509_export($certificate, $pem);
        openssl_pkey_export($key, $keyPem);
        $this->certificatePem = $pem;
        $path = $this->directory.'/cert.pem';
        file_put_contents($path, $pem.$keyPem);

        return $path;
    }
}
