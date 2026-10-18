<?php

namespace App\Clients\Pushover;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushover's validation endpoint over Laravel's HTTP client (feature 005 research R14):
 * `POST <RESTV2_PUSHOVER_VALIDATE_URL>` with the form fields `token` and `user`, 10-second timeout
 * (legacy has none). HTTP 200 with `status: 1` accepts; a 4xx answer rejects with Pushover's
 * `errors`; connection errors, timeouts and 5xx answers are unavailability.
 */
class HttpPushoverClient implements PushoverClient
{
    public const TIMEOUT_SECONDS = 10;

    public function validate(string $token, string $user): PushoverVerdict
    {
        try {
            $response = Http::asForm()
                ->timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->post((string) config('restv2.pushover_validate_url'), ['token' => $token, 'user' => $user]);
        } catch (ConnectionException $e) {
            Log::warning('Pushover is unreachable: '.$e->getMessage());

            return PushoverVerdict::unavailable();
        }

        if ($response->serverError()) {
            return PushoverVerdict::unavailable();
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful() && (int) ($body['status'] ?? 0) === 1) {
            return PushoverVerdict::accepted();
        }

        $errors = array_values(array_filter((array) ($body['errors'] ?? []), 'is_string'));

        return PushoverVerdict::rejected($errors === [] ? 'Pushover answered HTTP '.$response->status().'.' : implode('; ', $errors));
    }
}
