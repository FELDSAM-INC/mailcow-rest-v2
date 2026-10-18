<?php

namespace App\Clients\Memcached;

use Illuminate\Support\Facades\Log;

/**
 * memcached's text protocol over a plain socket (feature 002 research D13): `flush_all`, expecting
 * `OK`, within 2 seconds. No PHP extension is needed for one command.
 */
class SocketMemcachedFlusher implements MemcachedFlusher
{
    private const TIMEOUT = 2.0;

    public function flush(): bool
    {
        $address = sprintf('tcp://%s:%d', (string) config('restv2.memcached.host'), (int) config('restv2.memcached.port'));
        $socket = @stream_socket_client($address, $errno, $error, self::TIMEOUT);

        if ($socket === false) {
            Log::warning("The groupware cache at {$address} could not be flushed: {$error} ({$errno}).");

            return false;
        }

        stream_set_timeout($socket, (int) self::TIMEOUT);
        $written = @fwrite($socket, "flush_all\r\n");
        $answer = $written === false ? false : fgets($socket);
        fclose($socket);

        if ($answer === false || trim($answer) !== 'OK') {
            Log::warning("The groupware cache at {$address} did not confirm the flush.");

            return false;
        }

        return true;
    }
}
