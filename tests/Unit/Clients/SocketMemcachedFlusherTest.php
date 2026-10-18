<?php

namespace Tests\Unit\Clients;

use App\Clients\Memcached\SocketMemcachedFlusher;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Feature 002 T010 (research D13): the groupware cache is flushed with memcached's text protocol;
 * failures are logged and ignored like legacy flush_memcached().
 */
class SocketMemcachedFlusherTest extends TestCase
{
    public function test_flush_all_is_sent_and_ok_is_success(): void
    {
        // A one-shot memcached stand-in in its own process: it prints its port, then what it received.
        $script = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            echo explode(':', stream_socket_get_name($server, false))[1], "\n";
            $connection = stream_socket_accept($server, 10);
            $line = fgets($connection);
            fwrite($connection, "OK\r\n");
            fclose($connection);
            echo json_encode($line), "\n";
            PHP;
        $process = new Process([(new PhpExecutableFinder)->find(false) ?: 'php', '-r', $script]);
        $process->start();

        $port = null;
        $process->waitUntil(function (string $type, string $output) use (&$port): bool {
            if (preg_match('/^(\d+)\n/', $output, $m) === 1) {
                $port = (int) $m[1];
            }

            return $port !== null;
        });

        config(['restv2.memcached.host' => '127.0.0.1', 'restv2.memcached.port' => $port]);

        $this->assertTrue($this->app->make(SocketMemcachedFlusher::class)->flush());

        $process->wait();
        $lines = explode("\n", trim($process->getOutput()));
        $this->assertSame("flush_all\r\n", json_decode($lines[1], true));
    }

    public function test_an_unreachable_server_is_logged_and_returns_false_quickly(): void
    {
        config(['restv2.memcached.host' => '127.0.0.1', 'restv2.memcached.port' => 1]);
        $log = Log::spy();

        $started = microtime(true);
        $result = $this->app->make(SocketMemcachedFlusher::class)->flush();

        $this->assertFalse($result);
        $this->assertLessThan(2.5, microtime(true) - $started);
        $log->shouldHaveReceived('warning')->once();
    }
}
