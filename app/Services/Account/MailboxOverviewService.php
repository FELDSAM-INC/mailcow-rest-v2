<?php

namespace App\Services\Account;

use App\Auth\Principal;
use App\Services\Mailboxes\MailboxReader;
use App\Services\SenderSettings\SenderSettingsReader;
use App\Support\Domain\DomainName;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in mailbox user's overview (feature 005 FR-069, research R18), what the legacy user
 * page shows: feature 003's mailbox representation for quota, usage, protocol access, webmail
 * preference, password-changed time, recovery address, temporary aliases and push notifications,
 * and legacy user_get_alias_details() for the aliases, alias-domain addresses, send-as entries and
 * catch-alls. Texts are plain text (legacy HTML-encodes the comments).
 */
class MailboxOverviewService
{
    public function __construct(private readonly MailboxReader $mailboxes) {}

    /**
     * @return array<string, mixed> contract `MailboxOverview`
     */
    public function overview(Principal $principal): array
    {
        $username = $principal->username;
        $row = DB::table('mailbox')->where('username', $username)->first();

        if ($row === null) {
            return [];
        }

        $mailbox = $this->mailboxes->present([$row], $principal, tolerateSharedStore: true)[0];
        $domain = (string) $row->domain;
        $aliasDomains = $this->aliasDomains($domain);
        $mailcowSource = (string) $row->authsource === 'mailcow';

        return [
            'address' => $username,
            'localPart' => (string) $row->local_part,
            'domain' => $domain,
            'domainUnicode' => DomainName::toUnicode($domain),
            'displayName' => (string) ($row->name ?? ''),
            'authSource' => $mailbox['authSource'],
            'quota' => [
                'quotaBytes' => $mailbox['quota'],
                'usedBytes' => $mailbox['quotaUsed'],
                'messages' => $mailbox['messageCount'],
                'percentUsed' => $mailbox['percentUsed'],
            ],
            'protocolAccess' => $mailbox['protocolAccess'],
            'webmail' => ['enabled' => ! config('restv2.skip_sogo'), 'redirectAfterSignIn' => $mailbox['webmailRedirect']],
            'passwordChangedAt' => $mailbox['passwordChangedAt'],
            'recoveryEmail' => $mailcowSource ? $mailbox['recoveryEmail'] : null,
            'directAliases' => $this->directAliases($username),
            'sharedAliases' => $this->sharedAliases($username),
            'aliasDomainAddresses' => array_map(fn (string $aliasDomain): array => ['address' => $row->local_part.'@'.$aliasDomain, 'aliasDomain' => $aliasDomain], $aliasDomains),
            'sendAs' => $this->sendAs($username),
            'catchAllAddresses' => DB::table('alias')->whereRaw('`goto` REGEXP ?', [SenderSettingsReader::deliversTo($username)])->where('address', 'like', '@%')
                ->orderBy('address')->pluck('address')->map(fn (mixed $a): string => (string) $a)->all(),
            'temporaryAliasDomains' => [$domain, ...$aliasDomains],
            'activeAppPasswords' => DB::table('app_passwd')->where('mailbox', $username)->where('active', '!=', 0)->count(),
            'activeTemporaryAliases' => $mailbox['activeTemporaryAliases'],
            'pushNotificationsActive' => $mailbox['pushNotificationsActive'],
        ];
    }

    /**
     * The alias domains of a domain, by name (legacy user_get_alias_details(), active or not).
     *
     * @return list<string>
     */
    public function aliasDomains(string $domain): array
    {
        return DB::table('alias_domain')->where('target_domain', $domain)->orderBy('alias_domain')->pluck('alias_domain')
            ->map(fn (mixed $d): string => (string) $d)->all();
    }

    /**
     * Aliases whose only target is the mailbox, not catch-alls, not the mailbox's own row.
     *
     * @return list<array{address: string, publicComment: string|null}>
     */
    private function directAliases(string $username): array
    {
        return $this->entries(DB::table('alias')->where('goto', $username)->where('address', 'not like', '@%')->where('address', '!=', $username));
    }

    /**
     * Aliases with the mailbox among several targets.
     *
     * @return list<array{address: string, publicComment: string|null}>
     */
    private function sharedAliases(string $username): array
    {
        return $this->entries(DB::table('alias')
            ->whereRaw('`goto` REGEXP ?', [SenderSettingsReader::deliversTo($username)])
            ->where('address', 'not like', '@%')
            ->where('goto', '!=', $username)
            ->where('address', '!=', $username));
    }

    /**
     * @return list<array{address: string, publicComment: string|null}>
     */
    private function entries(Builder $query): array
    {
        return array_values($query->orderBy('address')->get(['address', 'public_comment'])->map(fn (object $row): array => [
            'address' => (string) $row->address,
            'publicComment' => is_string($row->public_comment) && $row->public_comment !== '' ? htmlspecialchars_decode($row->public_comment, ENT_QUOTES | ENT_HTML401) : null,
        ])->all());
    }

    /**
     * What the mailbox may send as besides its own addresses (`sender_acl`, legacy
     * aliases_also_send_as and aliases_send_as_all): addresses, whole domains with their alias
     * domains, and `*`.
     *
     * @return array{addresses: list<string>, domains: list<string>, anyAddress: bool}
     */
    private function sendAs(string $username): array
    {
        $entries = DB::table('sender_acl')->where('logged_in_as', $username)->where('external', 0)->pluck('send_as')->map(fn (mixed $v): string => (string) $v)->all();
        $addresses = [];
        $domains = [];
        $any = false;

        foreach ($entries as $entry) {
            if ($entry === '*') {
                $any = true;
            } elseif (str_starts_with($entry, '@')) {
                $domains[] = $entry;

                foreach ($this->aliasDomains(substr($entry, 1)) as $aliasDomain) {
                    $domains[] = '@'.$aliasDomain;
                }
            } else {
                $addresses[] = $entry;
            }
        }

        sort($addresses);
        $domains = array_values(array_unique($domains));
        sort($domains);

        return ['addresses' => $addresses, 'domains' => $domains, 'anyAddress' => $any];
    }
}
