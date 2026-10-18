<?php

namespace App\Clients\Ispconfig;

use App\Services\Dkim\IspconfigSettings;
use App\Support\Clock\Deadline;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * ISPConfig's JSON remote API (`…/remote/json.php?<function>` with a JSON body of the remoting
 * function's parameters, answers `{code, message, response}`), calling the same functions as the
 * fork's SOAP client: login, dns_zone_get, dns_rr_get_all_by_zone, dns_txt_add, dns_txt_update,
 * dns_txt_delete and logout (feature 010 research R11).
 *
 * Each call gets `timeout(remaining)` and `connectTimeout(min(5, remaining))` of the deadline; no
 * call starts with less than 0.5 seconds left. The password never appears in an exception or log.
 */
class JsonIspconfigDnsClient implements IspconfigDnsClient
{
    private const MIN_CALL_SECONDS = 0.5;

    private ?Deadline $deadline = null;

    private ?string $sessionId = null;

    private readonly IspconfigSettings $settings;

    public function __construct(?IspconfigSettings $settings = null)
    {
        $this->settings = $settings ?? IspconfigSettings::fromConfig();
    }

    public function open(Deadline $deadline): void
    {
        $this->deadline = $deadline;
        $session = $this->call('login', ['username' => $this->settings->username, 'password' => $this->settings->password()], 'authenticationFailed');

        if (! is_string($session) || $session === '') {
            throw new IspconfigException('invalidResponse', 'login answered without a session ID.');
        }

        $this->sessionId = $session;
    }

    public function findZone(string $origin): ?IspconfigZone
    {
        $zones = $this->call('dns_zone_get', ['session_id' => $this->session(), 'primary_id' => ['origin' => $origin]]);

        if ($zones === false || $zones === [] || $zones === null) {
            return null;
        }

        if (! is_array($zones)) {
            throw new IspconfigException('invalidResponse', 'dns_zone_get answered with an unexpected shape.');
        }

        // A primary_id array returns a list of zones; a single zone may come back unwrapped.
        $candidates = array_is_list($zones) ? $zones : [$zones];

        foreach ($candidates as $zone) {
            if (is_array($zone) && isset($zone['id'], $zone['origin']) && strcasecmp((string) $zone['origin'], $origin) === 0) {
                return new IspconfigZone((int) $zone['id'], (string) $zone['origin'], (int) ($zone['sys_groupid'] ?? 0));
            }
        }

        return null;
    }

    public function records(int $zoneId): array
    {
        $rows = $this->call('dns_rr_get_all_by_zone', ['session_id' => $this->session(), 'zone_id' => $zoneId]);

        if ($rows === false || $rows === null) {
            return [];
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new IspconfigException('invalidResponse', 'dns_rr_get_all_by_zone answered with an unexpected shape.');
        }

        $records = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['id'], $row['name'], $row['type'])) {
                throw new IspconfigException('invalidResponse', 'dns_rr_get_all_by_zone answered with an incomplete record.');
            }

            $records[] = new IspconfigRecord((int) $row['id'], (string) $row['name'], strtoupper((string) $row['type']), (string) ($row['data'] ?? ''));
        }

        return $records;
    }

    public function addTxt(int $clientId, array $params): void
    {
        $this->call('dns_txt_add', ['session_id' => $this->session(), 'client_id' => $clientId, 'params' => $params]);
    }

    public function updateTxt(int $clientId, int $recordId, array $params): void
    {
        $this->call('dns_txt_update', ['session_id' => $this->session(), 'client_id' => $clientId, 'primary_id' => $recordId, 'params' => $params]);
    }

    public function deleteTxt(int $recordId): void
    {
        $this->call('dns_txt_delete', ['session_id' => $this->session(), 'primary_id' => $recordId]);
    }

    public function close(): void
    {
        if ($this->sessionId === null) {
            return;
        }

        $session = $this->sessionId;
        $this->sessionId = null;

        try {
            $this->call('logout', ['session_id' => $session]);
        } catch (Throwable) {
            // The session expires on the server; nothing else to do.
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['url' => $this->settings->url, 'username' => $this->settings->username, 'session' => $this->sessionId === null ? null : '***'];
    }

    private function session(): string
    {
        return $this->sessionId ?? throw new IspconfigException('invalidResponse', 'No ISPConfig session is open.');
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function call(string $function, array $body, string $refusalReason = 'remoteFault'): mixed
    {
        $remaining = $this->deadline?->remaining() ?? (float) $this->settings->timeout;

        if ($remaining < self::MIN_CALL_SECONDS) {
            throw new IspconfigException('timeout', "The publication time limit was reached before {$function}.");
        }

        try {
            $request = Http::acceptJson()
                ->asJson()
                ->timeout($remaining)
                ->connectTimeout(min(5.0, $remaining));
            $request = $this->settings->verifyTls ? $request : $request->withoutVerifying();
            $response = $request->post($this->settings->url.'?'.$function, $body);
        } catch (ConnectionException $e) {
            $timeout = str_contains($e->getMessage(), 'cURL error 28') || stripos($e->getMessage(), 'timed out') !== false;

            throw new IspconfigException($timeout ? 'timeout' : 'unreachable', $this->scrub($e->getMessage()));
        }

        if ($response->status() !== 200) {
            throw new IspconfigException('invalidResponse', "{$function} answered HTTP ".$response->status().'.');
        }

        $answer = json_decode($response->body(), true);

        if (! is_array($answer) || ! array_key_exists('code', $answer)) {
            throw new IspconfigException('invalidResponse', "{$function} did not answer with a remote API object.");
        }

        if ($answer['code'] !== 'ok') {
            throw new IspconfigException($refusalReason, $this->scrub("{$function}: ".(is_string($answer['message'] ?? null) ? $answer['message'] : (string) json_encode($answer['message'] ?? null))));
        }

        return $answer['response'] ?? null;
    }

    private function scrub(string $text): string
    {
        $password = $this->settings->password();

        return $password === '' ? $text : str_replace($password, '***', $text);
    }
}
