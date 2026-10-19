<?php

namespace App\Services\Account;

use App\Services\Config\MailcowConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The client configuration of the signed-in mailbox (feature 005 FR-083, research R18): what
 * legacy's client setup help shows, from the server's autodiscover settings (`$autodiscover_config`
 * after the operator's vars.local.inc.php, the HTTPS port following the request as legacy's
 * auto-detection does). CalDAV, CardDAV and ActiveSync only when the groupware is enabled.
 */
class ClientConfigurationService
{
    private const DOCUMENTATION = 'https://mailcow.github.io/mailcow-dockerized-docs/client/client/#';

    public function __construct(private readonly MailcowConfig $config) {}

    /**
     * @return array<string, mixed> contract `MailboxClientConfiguration`
     */
    public function forMailbox(string $mailbox, Request $request): array
    {
        $autodiscover = $this->config->autodiscoverSettings($request);
        $name = (string) DB::table('mailbox')->where('username', $mailbox)->value('name');
        $groupware = ! config('restv2.skip_sogo');
        $outlookEas = ($autodiscover['useEASforOutlook'] ?? 'no') === 'yes';
        $dav = fn (string $kind): ?array => $groupware && is_array($autodiscover[$kind] ?? null) && self::port($autodiscover[$kind]['port'] ?? null) !== null
            ? ['host' => (string) $autodiscover[$kind]['server'], 'port' => self::port($autodiscover[$kind]['port']), 'principalPath' => '/SOGo/dav/'.$mailbox.'/']
            : null;
        $activeSyncUrl = $autodiscover['activesync']['url'] ?? null;

        // legacy user.php's $clientconfigstr, each value urlencode()d.
        $parameters = 'host='.urlencode((string) config('restv2.mailcow_hostname'))
            .'&email='.urlencode($mailbox)
            .'&name='.urlencode($name)
            .'&ui='.urlencode((string) strtok($request->getHttpHost(), ':'))
            .'&port='.urlencode((string) ($autodiscover['caldav']['port'] ?? ''))
            .($outlookEas ? '&outlookEAS=1' : '');

        return [
            'address' => $mailbox,
            'displayName' => $name === '' ? $mailbox : $name,
            'imap' => self::server($autodiscover['imap'] ?? null),
            'pop3' => self::server($autodiscover['pop3'] ?? null),
            'smtp' => self::server($autodiscover['smtp'] ?? null),
            'caldav' => $dav('caldav'),
            'carddav' => $dav('carddav'),
            'activeSync' => $groupware && is_string($activeSyncUrl) && $activeSyncUrl !== '' ? ['url' => $activeSyncUrl] : null,
            'authentication' => 'password',
            'autodiscoverType' => ($autodiscover['autodiscoverType'] ?? 'activesync') === 'imap' ? 'imap' : 'activesync',
            'outlookUsesActiveSync' => $outlookEas,
            'documentationUrl' => self::DOCUMENTATION.$parameters,
        ];
    }

    /**
     * @return array{host: string, tlsPort: int|null, starttlsPort: int|null}
     */
    private static function server(mixed $settings): array
    {
        $settings = is_array($settings) ? $settings : [];

        return [
            'host' => (string) ($settings['server'] ?? config('restv2.mailcow_hostname')),
            'tlsPort' => self::port($settings['port'] ?? null),
            'starttlsPort' => self::port($settings['tlsport'] ?? null),
        ];
    }

    private static function port(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value >= 1 && (int) $value <= 65535 ? (int) $value : null;
    }
}
