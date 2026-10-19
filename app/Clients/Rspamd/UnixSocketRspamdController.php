<?php

namespace App\Clients\Rspamd;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The Rspamd controller over its Unix socket (feature 006 research R2, feature 011 research R16):
 * Laravel's HTTP client with `CURLOPT_UNIX_SOCKET_PATH` = `restv2.rspamd.socket` and the base URL
 * `http://rspamd`, exactly as legacy calls it. The socket is trusted by Rspamd, so no password is
 * sent. Feature 011's reads wait 5 seconds for the connection and 10 seconds in total; feature
 * 006's learning calls and the action thresholds time out after `restv2.rspamd.timeout` seconds
 * (30, legacy's CURLOPT_TIMEOUT, FR-062).
 */
class UnixSocketRspamdController implements RspamdController
{
    public function stat(): array
    {
        return $this->read('/stat', $this->request());
    }

    public function history(): array
    {
        return $this->read('/history', $this->request());
    }

    public function actions(): array
    {
        return $this->read('/actions', $this->request(self::timeout()));
    }

    public function learnHam(string $message): LearnOutcome
    {
        return $this->learn('/learnham', $message);
    }

    public function learnSpam(string $message): LearnOutcome
    {
        return $this->learn('/learnspam', $message);
    }

    public function fuzzyAdd(string $message, int $flag): LearnOutcome
    {
        return $this->learn('/fuzzyadd', $message, ['Flag' => (string) $flag]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function learn(string $path, string $message, array $headers = []): LearnOutcome
    {
        try {
            $response = $this->request(self::timeout())
                ->withHeaders($headers)
                ->withBody($message, 'text/plain')
                ->post('http://rspamd'.$path);
        } catch (Throwable $e) {
            return LearnOutcome::failed($e->getMessage());
        }

        return self::classify($response);
    }

    /**
     * Legacy's classification: an `error` member counts as success when it says "already
     * learned" (any status), and a fuzzy call without content is reported as such; otherwise any
     * error or non-2xx status fails. HTTP 208 is "already learned".
     */
    private static function classify(Response $response): LearnOutcome
    {
        $document = json_decode($response->body(), true);
        $error = is_array($document) && is_string($document['error'] ?? null) ? $document['error'] : null;

        if ($error !== null) {
            return match (true) {
                stripos($error, 'already learned') !== false => LearnOutcome::alreadyLearned(),
                stripos($error, 'No content to generate fuzzy') !== false => LearnOutcome::noFuzzyContent(),
                default => LearnOutcome::failed($error),
            };
        }

        return match (true) {
            $response->status() === 208 => LearnOutcome::alreadyLearned(),
            $response->successful() => LearnOutcome::done(),
            default => LearnOutcome::failed("The Rspamd controller answered HTTP {$response->status()}."),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function read(string $path, PendingRequest $request): array
    {
        try {
            $response = $request->get('http://rspamd'.$path);
        } catch (Throwable $e) {
            throw new RspamdUnavailable('The Rspamd controller is unreachable: '.$e->getMessage(), 0, $e);
        }

        $document = $response->json();

        if (! $response->successful() || ! is_array($document)) {
            throw new RspamdUnavailable("The Rspamd controller answered {$path} with HTTP {$response->status()}.");
        }

        return $document;
    }

    private function request(?int $timeout = null): PendingRequest
    {
        return Http::withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => (string) config('restv2.rspamd.socket', '/var/lib/rspamd/rspamd.sock')]])
            ->acceptJson()
            ->connectTimeout($timeout === null ? 5 : min(5, $timeout))
            ->timeout($timeout ?? 10);
    }

    private static function timeout(): int
    {
        return max(1, (int) config('restv2.rspamd.timeout', 30));
    }
}
