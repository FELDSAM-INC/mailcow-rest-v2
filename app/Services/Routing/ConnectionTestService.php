<?php

namespace App\Services\Routing;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\Dns\DnsResolver;
use App\Clients\Dns\DnsTemporaryFailure;
use App\Clients\Smtp\SmtpProbe;
use App\Clients\Smtp\SmtpProbeRequest;
use App\Clients\Smtp\SmtpProbeResult;
use App\Clients\Smtp\SmtpTranscript;
use App\Support\Routing\NextHop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Connection tests of relayhosts and transports, legacy ajax/transport_check.php (feature 009
 * FR-041–FR-045, research R13): the stored next hop is parsed (a legacy value that does not parse
 * is tested the legacy way: brackets removed, a trailing `:digits` taken as the port); without
 * brackets its most preferred mail exchanger is used (the first returned on a tie), else the host
 * itself; the port is the next hop's or 25; the host is resolved through DnsResolver; then the SMTP
 * dialogue runs with the stored credentials (read explicitly here, never in a representation)
 * within the overall deadline, which counts from the start of the request. Nothing is stored; one
 * audit record (sender, recipient, outcome, step) is written afterwards in its own transaction.
 */
class ConnectionTestService
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly SmtpProbe $probe,
        private readonly RoutingSettings $settings,
        private readonly RoutingAudit $audit,
    ) {}

    /**
     * @return array<string, mixed> contract `RoutingConnectionTestResult`
     */
    public function testRelayhost(Principal $principal, int $id, ?string $sender, ?string $recipient): array
    {
        $row = DB::table('relayhosts')->where('id', $id)->first(['id', 'hostname', 'username', 'password']) ?? throw new NotFoundHttpException('The relayhost does not exist.');
        $result = $this->run((string) $row->hostname, (string) $row->username, (string) $row->password, $sender, $recipient);
        $this->record(AuditEventType::RelayhostTest, $principal, RoutingAudit::target('relayhost', $id, (string) $row->hostname), $result);

        return $result;
    }

    /**
     * @return array<string, mixed> contract `RoutingConnectionTestResult`
     */
    public function testTransport(Principal $principal, int $id, ?string $sender, ?string $recipient): array
    {
        $row = DB::table('transports')->where('id', $id)->first(['id', 'destination', 'nexthop', 'username', 'password']) ?? throw new NotFoundHttpException('The transport does not exist.');
        $result = $this->run((string) $row->nexthop, (string) $row->username, (string) $row->password, $sender, $recipient);
        $this->record(AuditEventType::TransportTest, $principal, RoutingAudit::target('transport', $id, (string) $row->destination), $result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function run(string $nextHop, string $username, string $password, ?string $sender, ?string $recipient): array
    {
        $started = microtime(true);
        $startedAt = CarbonImmutable::now('UTC');
        $sender ??= $this->settings->testSender();
        $recipient ??= $this->settings->testRecipient();
        $total = (float) $this->settings->totalTimeout();
        $notes = new SmtpTranscript;
        [$host, $port, $bracketed] = self::target($nextHop);
        $hostSource = 'nextHop';
        $login = $username === '' ? 'notConfigured' : 'notReached';

        if (! $bracketed && filter_var($host, FILTER_VALIDATE_IP) === false) {
            try {
                $exchangers = $this->dns->mx($host);
            } catch (DnsTemporaryFailure) {
                $exchangers = [];
            }

            if ($exchangers !== []) {
                $preferred = $exchangers[0];

                foreach ($exchangers as $exchanger) {
                    if ($exchanger['preference'] < $preferred['preference']) {
                        $preferred = $exchanger;
                    }
                }

                $notes->info("Using the most preferred mail exchanger of {$host}: {$preferred['host']}");
                $host = $preferred['host'];
                $hostSource = 'mailExchanger';
            } else {
                $notes->info("No mail exchanger of {$host} was found; using the host itself.");
            }
        }

        $base = [
            'nextHop' => $nextHop,
            'port' => $port,
            'hostSource' => $hostSource,
            'sender' => $sender,
            'recipient' => $recipient,
            'startedAt' => $startedAt->format('Y-m-d\TH:i:s\Z'),
        ];

        try {
            $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : [...$this->dns->a($host), ...$this->dns->aaaa($host)];
        } catch (DnsTemporaryFailure) {
            return $this->unresolved($base, $notes, $login, $started, 'dnsTemporaryFailure', "The addresses of {$host} could not be looked up (DNS failed temporarily).");
        }

        if ($addresses === []) {
            return $this->unresolved($base, $notes, $login, $started, 'nameNotResolved', "{$host} has no address.");
        }

        $result = $this->probe->probe(new SmtpProbeRequest(
            host: $host,
            addresses: $addresses,
            port: $port,
            heloName: (string) (config('restv2.mailcow_hostname') ?: 'localhost'),
            username: $username === '' ? null : $username,
            password: $username === '' ? null : $password,
            sender: $sender,
            recipient: $recipient,
            stepTimeout: (float) $this->settings->stepTimeout(),
            totalTimeout: max(0.1, $total - (microtime(true) - $started)),
        ));

        return $base + [
            'accepted' => $result->accepted,
            'step' => $result->step,
            'failure' => $result->failureReason === null ? null : ['reason' => $result->failureReason, 'message' => (string) $result->failureMessage],
            'host' => $result->step === 'connect' && ! $result->accepted ? null : $host,
            'tls' => $result->tls,
            'login' => $result->login,
            'lastReply' => $result->lastReplyCode === null ? null : ['code' => $result->lastReplyCode, 'text' => (string) $result->lastReplyText],
            'transcript' => self::merge($notes->lines(), $result),
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * The host, port and bracket state of a stored next hop; a value the grammar refuses is read
     * the legacy way (brackets removed, a trailing `:digits` as the port).
     *
     * @return array{string, int, bool}
     */
    public static function target(string $nextHop): array
    {
        $hop = NextHop::tryParse($nextHop);

        if ($hop !== null) {
            return [$hop->host(), $hop->port() ?? 25, $hop->bracketed()];
        }

        $bracketed = str_contains($nextHop, '[');
        $host = trim(str_replace(['[', ']'], '', $nextHop));
        $port = 25;

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false && preg_match('/^(.*):([0-9]{1,5})$/', $host, $m) === 1 && (int) $m[2] >= 1 && (int) $m[2] <= 65535) {
            [$host, $port] = [$m[1], (int) $m[2]];
        }

        return [str_starts_with(strtolower($host), 'ipv6:') ? substr($host, 5) : $host, $port, $bracketed];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function unresolved(array $base, SmtpTranscript $notes, string $login, float $started, string $reason, string $message): array
    {
        $notes->info($message);

        return $base + [
            'accepted' => false,
            'step' => 'resolve',
            'failure' => ['reason' => $reason, 'message' => $message],
            'host' => null,
            'tls' => false,
            'login' => $login,
            'lastReply' => null,
            'transcript' => $notes->lines(),
            'durationMs' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * The service's notes followed by the dialogue, within the transcript's cap.
     *
     * @param  list<array{direction: string, text: string}>  $notes
     * @return list<array{direction: string, text: string}>
     */
    private static function merge(array $notes, SmtpProbeResult $result): array
    {
        $lines = [...$notes, ...$result->transcript];

        if (count($lines) <= SmtpTranscript::MAX_LINES + 1) {
            return $lines;
        }

        return [...array_slice($lines, 0, SmtpTranscript::MAX_LINES), ['direction' => 'info', 'text' => 'The transcript was cut to '.SmtpTranscript::MAX_LINES.' lines.']];
    }

    /**
     * @param  array{type: string, id: string|null, name: string|null}  $target
     * @param  array<string, mixed>  $result
     */
    private function record(AuditEventType $event, Principal $principal, array $target, array $result): void
    {
        DB::transaction(fn () => $this->audit->success($event, $principal, $target, [
            'sender' => $result['sender'],
            'recipient' => $result['recipient'],
            'accepted' => $result['accepted'],
            'step' => $result['step'],
            'failureReason' => $result['failure']['reason'] ?? null,
        ]));
    }
}
