<?php

namespace Tests\Support\Lmtp;

use RuntimeException;

/**
 * Runs tests/Support/Lmtp/lmtp-stub.php as a child process on an ephemeral port (feature 013 T064)
 * and gives the test its port and the recorded dialogue.
 */
final class LmtpStubServer
{
    /** @var resource */
    private $process;

    public readonly int $port;

    private readonly string $dir;

    /**
     * @param  array<string, mixed>  $script
     */
    public function __construct(array $script = [])
    {
        $this->dir = sys_get_temp_dir().'/restv2-lmtp-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700);
        file_put_contents($this->dir.'/script.json', json_encode($script));
        touch($this->dir.'/log');
        $process = proc_open([PHP_BINARY, __DIR__.'/lmtp-stub.php', $this->dir.'/script.json', $this->dir.'/port', $this->dir.'/log'], [], $pipes);

        if (! is_resource($process)) {
            throw new RuntimeException('The LMTP stub could not be started.');
        }

        $this->process = $process;

        for ($i = 0; $i < 200 && ! is_file($this->dir.'/port'); $i++) {
            usleep(20000);
        }

        $port = (int) @file_get_contents($this->dir.'/port');

        if ($port < 1) {
            $this->stop();

            throw new RuntimeException('The LMTP stub did not start listening.');
        }

        $this->port = $port;
    }

    /**
     * The dialogue: `C: ` lines received, `S: ` replies sent.
     *
     * @return list<string>
     */
    public function log(): array
    {
        usleep(50000);

        return array_values(array_filter(explode("\n", (string) file_get_contents($this->dir.'/log')), fn (string $l): bool => $l !== ''));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }

        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }
}
