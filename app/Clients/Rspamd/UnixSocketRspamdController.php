<?php

namespace App\Clients\Rspamd;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Rspamd controller over its Unix socket (feature 006 research R2, feature 011 research R16):
 * Laravel's HTTP client with `CURLOPT_UNIX_SOCKET_PATH` = `restv2.rspamd.socket` and the base URL
 * `http://rspamd`, exactly as legacy calls it. The socket is trusted by Rspamd, so no password is
 * sent. The reads wait 5 seconds for the connection and 10 seconds in total. Created by feature 011
 * with `stat()` and `history()`; feature 006 adds its learning calls.
 */
class UnixSocketRspamdController implements RspamdController
{
    public function stat(): array
    {
        return $this->read('/stat');
    }

    public function history(): array
    {
        return $this->read('/history');
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        try {
            $response = $this->request()->get('http://rspamd'.$path);
        } catch (Throwable $e) {
            throw new RspamdUnavailable('The Rspamd controller is unreachable: '.$e->getMessage(), 0, $e);
        }

        $document = $response->json();

        if (! $response->successful() || ! is_array($document)) {
            throw new RspamdUnavailable("The Rspamd controller answered {$path} with HTTP {$response->status()}.");
        }

        return $document;
    }

    private function request(): PendingRequest
    {
        return Http::withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => (string) config('restv2.rspamd.socket', '/var/lib/rspamd/rspamd.sock')]])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(10);
    }
}
