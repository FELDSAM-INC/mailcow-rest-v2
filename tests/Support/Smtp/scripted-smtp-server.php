<?php

/*
 * A one-session SMTP server for StreamSmtpProbeTest (feature 009 T057, research R14). It listens on
 * 127.0.0.1 at a free port, prints the port on its first output line, accepts one connection and
 * plays a JSON script of steps, recording every line it receives:
 *
 *   {"send": "220 mx.example ESMTP"}       one reply line (CRLF added)
 *   {"expect": "EHLO"}                     read one line and record it
 *   {"data": true}                         read the message data up to "." and record it
 *   {"starttls": true}                     switch the connection to TLS (server side)
 *   {"garbage": true}                      write bytes that are not TLS, then close
 *   {"sleep": 1.5}                         wait (seconds)
 *   {"close": true}                        close the connection
 *
 *   php scripted-smtp-server.php <script.json> <received.json> [<cert.pem>]
 *
 * It exits after the session, or after 30 seconds without a connection.
 */

[$self, $scriptPath, $receivedPath] = $argv + [null, null, null];
$certPath = $argv[3] ?? null;
$script = json_decode((string) file_get_contents((string) $scriptPath), true, flags: JSON_THROW_ON_ERROR);
$received = [];

$context = stream_context_create($certPath === null ? [] : ['ssl' => [
    'local_cert' => $certPath,
    'verify_peer' => false,
    'allow_self_signed' => true,
]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
echo substr($name, (int) strrpos($name, ':') + 1).PHP_EOL;
flush();

$save = function () use (&$received, $receivedPath): void {
    file_put_contents((string) $receivedPath, json_encode($received, JSON_THROW_ON_ERROR));
};

$client = @stream_socket_accept($server, 30);

if ($client === false) {
    $save();
    exit(0);
}

stream_set_timeout($client, 30);

foreach ($script as $step) {
    if (isset($step['send'])) {
        @fwrite($client, $step['send']."\r\n");
    } elseif (isset($step['expect'])) {
        $line = fgets($client, 8192);

        if ($line === false) {
            break;
        }

        $received[] = rtrim($line, "\r\n");
    } elseif (isset($step['data'])) {
        $lines = [];

        while (($line = fgets($client, 8192)) !== false) {
            $line = rtrim($line, "\r\n");

            if ($line === '.') {
                break;
            }

            $lines[] = $line;
        }

        $received[] = ['data' => $lines];
    } elseif (isset($step['starttls'])) {
        stream_set_blocking($client, true);

        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
            $received[] = 'TLS HANDSHAKE FAILED';
            break;
        }
    } elseif (isset($step['garbage'])) {
        @fwrite($client, str_repeat("\x15\x03\x01\x00\x02\x02\x28", 4));
        fclose($client);
        $client = null;
        break;
    } elseif (isset($step['sleep'])) {
        usleep((int) ((float) $step['sleep'] * 1_000_000));
    } elseif (isset($step['close'])) {
        fclose($client);
        $client = null;
        break;
    }
}

// Collect what the client still sends (for example QUIT) for a moment.
if (is_resource($client)) {
    stream_set_timeout($client, 1);

    while (($line = fgets($client, 8192)) !== false) {
        $received[] = rtrim($line, "\r\n");
    }

    @fclose($client);
}

$save();
