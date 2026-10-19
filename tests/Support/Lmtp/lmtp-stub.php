<?php

/*
 * A scripted LMTP server for tests/Unit/Clients/SocketLmtpClientTest.php (feature 013 T064), run as
 * a child process by Tests\Support\Lmtp\LmtpStubServer:
 *
 *   php lmtp-stub.php <script.json> <port file> <log file>
 *
 * It listens on an ephemeral port of 127.0.0.1 (written to the port file), serves connections one
 * after another and appends every received line (`C: ...`) and sent reply (`S: ...`) to the log.
 * The script names the final reply of each message in order (`finals`, the last repeating), an
 * optional delay before the DATA reply (`stallData`, seconds), and whether to close the connection
 * right after the greeting on the first connection (`dropFirst`).
 */

[$script, $portFile, $logFile] = [json_decode((string) file_get_contents($argv[1]), true), $argv[2], $argv[3]];
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

if ($server === false) {
    fwrite(STDERR, "cannot listen: {$errstr}\n");
    exit(1);
}

file_put_contents($portFile, (string) parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT));
$log = fn (string $line) => file_put_contents($logFile, $line."\n", FILE_APPEND);
$message = 0;
$connection = 0;

while ($client = @stream_socket_accept($server, 60)) {
    $connection++;
    $send = function (string $reply) use ($client, $log): void {
        fwrite($client, $reply."\r\n");
        $log('S: '.$reply);
    };
    $send('220 stub LMTP ready');

    if ($connection === 1 && ($script['dropFirst'] ?? false)) {
        fclose($client);

        continue;
    }

    $inData = false;

    while (($line = fgets($client)) !== false) {
        $line = rtrim($line, "\r\n");
        $log('C: '.$line);

        if ($inData) {
            if ($line === '.') {
                $inData = false;
                $finals = $script['finals'] ?? ['250 2.0.0 Saved'];
                $send($finals[min($message, count($finals) - 1)]);
                $message++;
            }

            continue;
        }

        $verb = strtoupper(substr($line, 0, 4));

        if ($verb === 'LHLO') {
            fwrite($client, "250-stub\r\n250-PIPELINING\r\n250 ENHANCEDSTATUSCODES\r\n");
            $log('S: 250 ENHANCEDSTATUSCODES');
        } elseif ($verb === 'MAIL' || $verb === 'RSET' || $verb === 'RCPT') {
            $send('250 2.1.0 OK');
        } elseif ($verb === 'DATA') {
            if (($script['stallData'] ?? 0) > 0) {
                sleep((int) $script['stallData']);
            }

            $send('354 OK');
            $inData = true;
        } elseif ($verb === 'QUIT') {
            $send('221 2.0.0 Bye');
            fclose($client);

            continue 2;
        } else {
            $send('500 5.5.1 Unknown command');
        }
    }
}
