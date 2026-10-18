<?php

namespace App\Services\Mailboxes;

use App\Clients\SharedStore\SharedStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\RateLimits\RateLimitStore;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The rules of a mailbox deletion that both the deletion and its preview use (feature 003 FR-041,
 * FR-065, research R12, R15): the aliases whose only recipient is the mailbox (its own alias not
 * counted) and those that only lose it, and the counts of every kind of dependent row.
 */
class MailboxDeletionPlanner
{
    public const SAMPLE = 100;

    public function __construct(
        private readonly SharedStore $store,
        private readonly MailStoreCommands $mailStore,
        private readonly MailboxVersion $version,
    ) {}

    public function plan(string $address): DeletionPlan
    {
        $exclusive = DB::table('alias')->where('goto', $address)->where('address', '!=', $address);
        $shared = DB::table('alias')->where('goto', '!=', $address)->whereRaw('`goto` REGEXP ?', [self::gotoPattern($address)]);
        $oauth = 0;

        foreach (['oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_authorization_codes'] as $table) {
            $oauth += DB::table($table)->where('user_id', $address)->count();
        }

        return new DeletionPlan(
            self::sample($exclusive),
            self::sample($shared),
            [
                'senderPermissionsHeld' => DB::table('sender_acl')->where('logged_in_as', $address)->count(),
                'senderPermissionsGranted' => DB::table('sender_acl')->where('send_as', $address)->where('logged_in_as', '!=', $address)->count(),
                'temporaryAliases' => DB::table('spamalias')->where('goto', $address)->count(),
                'syncJobs' => DB::table('imapsync')->where('user2', $address)->count(),
                'appPasswords' => DB::table('app_passwd')->where('mailbox', $address)->count(),
                'sieveFilters' => DB::table('sieve_filters')->where('username', $address)->count(),
                'spamFilterSettings' => DB::table('filterconf')->where('object', $address)->count(),
                'quarantineItems' => DB::table('quarantine')->where('rcpt', $address)->count(),
                'bccMaps' => DB::table('bcc_maps')->where('local_dest', $address)->count(),
                'oauthGrants' => $oauth,
                'secondFactors' => DB::table('tfa')->where('username', $address)->count(),
                'passkeys' => DB::table('fido2')->where('username', $address)->count(),
                'tags' => DB::table('tags_mailbox')->where('username', $address)->count(),
                'recentLogins' => DB::table('sasl_log')->where('username', $address)->count(),
            ],
        );
    }

    /**
     * The contract's `MailboxDeletionPreview` of a mailbox that exists.
     *
     * @return array<string, mixed>
     */
    public function preview(string $address): array
    {
        $row = DB::table('mailbox')->where('username', $address)->where('kind', '')->first() ?? throw new NotFoundHttpException('The mailbox does not exist.');
        $plan = $this->plan($address);
        $blockers = [];

        if (! $this->mailStore->mailStoreReachable()) {
            $blockers[] = ['dependency' => 'mailStore', 'detail' => 'mailcow\'s container service or the Dovecot container cannot be reached.'];
        }

        try {
            $this->store->ping();
            $rateLimit = $this->store->hasField(RateLimitStore::HASH, $address);
            $tagged = $this->store->hasField(TaggedMailHandlingStore::SUBJECT, $address) || $this->store->hasField(TaggedMailHandlingStore::SUBFOLDER, $address);
        } catch (SharedStoreUnavailable) {
            $blockers[] = ['dependency' => 'sharedStore', 'detail' => 'mailcow\'s shared store (Redis) cannot be reached.'];
            [$rateLimit, $tagged] = [null, null];
        }

        $usage = DB::table(config('restv2.master') ? 'quota2' : 'quota2replica')->where('username', $address)->first();

        return [
            'address' => $address,
            'canProceed' => $blockers === [],
            'blockers' => $blockers,
            'exclusiveAliases' => $plan->exclusiveAliases,
            'sharedAliases' => $plan->sharedAliases,
            'counts' => $plan->counts,
            'present' => [
                'pushNotificationSettings' => DB::table('pushover')->where('username', $address)->exists(),
                'webmailData' => $this->hasWebmailData($address),
                'rateLimit' => $rateLimit,
                'taggedMailHandling' => $tagged,
            ],
            'mail' => ['storageUsed' => max(0, (int) ($usage->bytes ?? 0)), 'messageCount' => max(0, (int) ($usage->messages ?? 0))],
            'garbageRetentionMinutes' => (int) config('restv2.maildir_gc_time', 7200),
            'etag' => $this->version->forMailbox(
                $row,
                DB::table('user_acl')->where('username', $address)->first(),
                DB::table('tags_mailbox')->where('username', $address)->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all(),
            ),
        ];
    }

    /**
     * Legacy's pattern of an address inside a comma-separated `goto` list.
     */
    public static function gotoPattern(string $address): string
    {
        return '(^|,)'.preg_quote($address, '/').'($|,)';
    }

    /**
     * The `sogo_acl` pattern of objects below the user's folders, with LIKE wildcards escaped.
     */
    public static function sogoObjectPattern(string $address): string
    {
        return '%/'.addcslashes($address, '\\%_').'/%';
    }

    private function hasWebmailData(string $address): bool
    {
        return DB::table('sogo_user_profile')->where('c_uid', $address)->exists()
            || DB::table('sogo_cache_folder')->where('c_uid', $address)->exists()
            || DB::table('sogo_folder_info')->where('c_path2', $address)->exists()
            || DB::table('sogo_acl')->where('c_uid', $address)->orWhere('c_object', 'like', self::sogoObjectPattern($address))->exists();
    }

    /**
     * @return array{count: int, addresses: list<string>}
     */
    private static function sample(Builder $query): array
    {
        return [
            'count' => (clone $query)->count(),
            'addresses' => $query->orderBy('address')->limit(self::SAMPLE)->pluck('address')->map(fn (mixed $a): string => (string) $a)->all(),
        ];
    }
}
