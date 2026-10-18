<?php

namespace App\Services\Mailboxes;

use App\Services\Groupware\SogoDirectory;
use Illuminate\Support\Facades\DB;

/**
 * The database part of a mailbox rename (feature 003 FR-045, FR-046, research R18), symmetric so
 * that its undo is the same call reversed. Runs inside the caller's transaction with foreign key
 * checks off for the session, as legacy does:
 *
 *  - legacy's statements: the `mailbox` row, its own alias and every column legacy renames (tags,
 *    Sieve filters, app passwords, user permissions, usage, push settings, sync jobs, BCC maps,
 *    recipient maps, sender permissions), the old address replaced inside every alias destination
 *    list (legacy only renames exact destinations), and the groupware entry; never `da_acl`, which
 *    belongs to a separate domain administrator account;
 *  - what legacy leaves under the old address: second factors, passkeys, temporary aliases, spam
 *    settings, quarantine items, recent logins and the API's own preferences;
 *  - forward only: the OAuth grants bound to the old address are revoked (not restored by the undo),
 *    stale rows of the new address in tables keyed by the address are removed, and the optional
 *    alias from the old to the new address is created. The undo removes that alias first.
 *
 * Later features with API-owned per-mailbox data register their tables here.
 */
class MailboxDataMover
{
    /** Columns that hold the mailbox's address and are renamed as they are. */
    private const COLUMNS = [
        ['tags_mailbox', 'username'],
        ['sieve_filters', 'username'],
        ['app_passwd', 'mailbox'],
        ['user_acl', 'username'],
        ['quota2', 'username'],
        ['quota2replica', 'username'],
        ['pushover', 'username'],
        ['imapsync', 'user2'],
        ['bcc_maps', 'local_dest'],
        ['bcc_maps', 'bcc_dest'],
        ['recipient_maps', 'old_dest'],
        ['recipient_maps', 'new_dest'],
        ['sender_acl', 'logged_in_as'],
        ['sender_acl', 'send_as'],
        // Carried over (beyond legacy).
        ['tfa', 'username'],
        ['fido2', 'username'],
        ['spamalias', 'goto'],
        ['filterconf', 'object'],
        ['quarantine', 'rcpt'],
        ['sasl_log', 'username'],
    ];

    /** Tables keyed by the address, whose stale rows of a free address would block the rename. */
    private const KEYED = ['quota2', 'quota2replica', 'pushover', 'sasl_log'];

    public function __construct(private readonly SogoDirectory $sogo) {}

    public function move(string $from, string $to, bool $forward, ?string $aliasComment): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            if ($forward) {
                foreach (self::KEYED as $table) {
                    DB::table($table)->where('username', $to)->delete();
                }

                DB::table('restv2_preferences')->where('account_kind', 'mailbox')->where('username', $to)->delete();
            } else {
                // The alias the forward move created (from the original address to the new one).
                DB::table('alias')->where('address', $to)->where('goto', $from)->where('private_comment', self::aliasComment($to, $from))->delete();
            }

            DB::table('mailbox')->where('username', $from)->update(['username' => $to, 'local_part' => explode('@', $to, 2)[0]]);
            DB::table('alias')->where('address', $from)->update(['address' => $to]);

            foreach (self::COLUMNS as [$table, $column]) {
                DB::table($table)->where($column, $from)->update([$column => $to]);
            }

            DB::table('restv2_preferences')->where('account_kind', 'mailbox')->where('username', $from)->update(['username' => $to]);

            foreach (DB::table('alias')->whereRaw('`goto` REGEXP ?', [MailboxDeletionPlanner::gotoPattern($from)])->get(['id', 'goto']) as $alias) {
                $gotos = array_map(fn (string $goto): string => strcasecmp(trim($goto), $from) === 0 ? $to : $goto, explode(',', (string) $alias->goto));
                DB::table('alias')->where('id', $alias->id)->update(['goto' => implode(',', $gotos)]);
            }

            if ($forward) {
                foreach (['oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_authorization_codes'] as $table) {
                    DB::table($table)->where('user_id', $from)->delete();
                }
            }

            DB::table('_sogo_static_view')->where('c_uid', $from)->update(['c_uid' => $to, 'c_name' => $to, 'mail' => $to]);

            if ($forward && $aliasComment !== null) {
                DB::table('alias')->insert([
                    'address' => $from,
                    'goto' => $to,
                    'domain' => explode('@', $from, 2)[1] ?? '',
                    'private_comment' => $aliasComment,
                    'sogo_visible' => 1,
                    'internal' => 0,
                    'sender_allowed' => 0,
                    'active' => 1,
                ]);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->sogo->refresh([$to, $from]);
    }

    /** Legacy's private comment of the alias that keeps the old address. */
    public static function aliasComment(string $from, string $to): string
    {
        return "Mailbox was renamed from {$from} to {$to}";
    }
}
