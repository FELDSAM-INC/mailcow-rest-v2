<?php

namespace App\Services\Groupware;

use App\Clients\Memcached\MemcachedFlusher;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The groupware's directory view `_sogo_static_view`, which SOGo reads for its users, aliases and
 * address books (feature 002 research D13). rebuild() is legacy update_sogo_static_view() without a
 * mailbox, the full rebuild legacy runs after a domain edit or deletion (not after a creation),
 * followed by the memcached flush.
 *
 * Deviation (spec parity table): `c_password` gets a fresh random `{SSHA256}` placeholder per
 * rebuild, which denies direct groupware logins; legacy builds its hash from undefined variables.
 * Nothing happens when the groupware is disabled (`SKIP_SOGO=y`).
 *
 * refresh() is the incremental form of feature 003 (research R14), legacy
 * update_sogo_static_view($mailbox) for given mailboxes: pure SQL, without the cache flush, so that
 * mailbox changes run it inside their transaction and flush memcached after the commit.
 */
class SogoDirectory
{
    public function __construct(private readonly MemcachedFlusher $flusher) {}

    public function rebuild(): GroupwareOutcome
    {
        if (config('restv2.skip_sogo')) {
            return GroupwareOutcome::GroupwareDisabled;
        }

        try {
            $this->writeView($this->placeholderHash());
        } catch (Throwable $e) {
            Log::error('The groupware directory could not be rebuilt: '.$e->getMessage());

            return GroupwareOutcome::Failed;
        }

        $this->flusher->flush();

        return GroupwareOutcome::Done;
    }

    /**
     * Updates the entries of the given mailboxes: an active mailbox's row is written with legacy's
     * statement restricted to it, the rows of the others (inactive, renamed away or deleted) are
     * removed, followed by legacy's orphan cleanup. Database failures are thrown, so that a caller's
     * transaction rolls back; the groupware cache is not flushed.
     *
     * @param  list<string>  $usernames
     */
    public function refresh(array $usernames): GroupwareOutcome
    {
        if (config('restv2.skip_sogo')) {
            return GroupwareOutcome::GroupwareDisabled;
        }

        $usernames = array_values(array_unique(array_filter($usernames, fn (string $u): bool => $u !== '')));

        if ($usernames === []) {
            return GroupwareOutcome::Done;
        }

        $active = DB::table('mailbox')->whereIn('username', $usernames)->where('active', 1)->pluck('username')
            ->map(fn (mixed $u): string => (string) $u)->all();
        $placeholder = $this->placeholderHash();

        $this->withoutFullGroupBy(function () use ($active, $placeholder): void {
            foreach ($active as $username) {
                $this->insertRows($placeholder, $username);
            }
        });

        $gone = array_values(array_udiff($usernames, $active, 'strcasecmp'));

        if ($gone !== []) {
            DB::table('_sogo_static_view')->whereIn('c_uid', $gone)->delete();
        }

        DB::statement("DELETE FROM _sogo_static_view WHERE `c_uid` NOT IN (SELECT `username` FROM `mailbox` WHERE `active` = '1')");

        return GroupwareOutcome::Done;
    }

    /**
     * Legacy's two statements, verbatim apart from the placeholder parameter. Legacy's GROUP BY
     * relies on MariaDB's default SQL mode, so ONLY_FULL_GROUP_BY (set by Laravel's strict mode) is
     * lifted for the statement.
     */
    protected function writeView(string $placeholderHash): void
    {
        $this->withoutFullGroupBy(fn () => $this->insertRows($placeholderHash));

        DB::statement("DELETE FROM _sogo_static_view WHERE `c_uid` NOT IN (SELECT `username` FROM `mailbox` WHERE `active` = '1')");
    }

    private function withoutFullGroupBy(Closure $statements): void
    {
        $mode = (string) (DB::selectOne('SELECT @@SESSION.sql_mode AS `mode`')->mode ?? '');
        $relaxed = implode(',', array_filter(explode(',', $mode), fn (string $m): bool => $m !== '' && $m !== 'ONLY_FULL_GROUP_BY'));
        DB::statement('SET SESSION sql_mode = ?', [$relaxed]);

        try {
            $statements();
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$mode]);
        }
    }

    /**
     * Legacy's INSERT … SELECT … ON DUPLICATE KEY UPDATE, for every active mailbox or, with a
     * username, only for that one (legacy's `AND mailbox.username = :mailbox`).
     */
    private function insertRows(string $placeholderHash, ?string $username = null): void
    {
        $restriction = $username === null ? '' : 'AND mailbox.username = ?';
        $bindings = $username === null ? [$placeholderHash] : [$placeholderHash, $username];

        DB::statement(<<<SQL
            INSERT INTO _sogo_static_view (`c_uid`, `domain`, `c_name`, `c_password`, `c_cn`, `mail`, `aliases`, `ad_aliases`, `ext_acl`, `kind`, `multiple_bookings`)
              SELECT
                mailbox.username,
                mailbox.domain,
                mailbox.username,
                ?,
                mailbox.name,
                mailbox.username,
                IFNULL(GROUP_CONCAT(ga.aliases ORDER BY ga.aliases SEPARATOR ' '), ''),
                IFNULL(gda.ad_alias, ''),
                IFNULL(external_acl.send_as_acl, ''),
                mailbox.kind,
                mailbox.multiple_bookings
              FROM
                mailbox
                LEFT OUTER JOIN grouped_mail_aliases ga ON ga.username REGEXP CONCAT('(^|,)', mailbox.username, '(\$|,)')
                LEFT OUTER JOIN grouped_domain_alias_address gda ON gda.username = mailbox.username
                LEFT OUTER JOIN grouped_sender_acl_external external_acl ON external_acl.username = mailbox.username
              WHERE
                mailbox.active = '1'
                {$restriction}
                GROUP BY mailbox.username
              ON DUPLICATE KEY UPDATE
                `domain` = VALUES(`domain`),
                `c_name` = VALUES(`c_name`),
                `c_password` = VALUES(`c_password`),
                `c_cn` = VALUES(`c_cn`),
                `mail` = VALUES(`mail`),
                `aliases` = VALUES(`aliases`),
                `ad_aliases` = VALUES(`ad_aliases`),
                `ext_acl` = VALUES(`ext_acl`),
                `kind` = VALUES(`kind`),
                `multiple_bookings` = VALUES(`multiple_bookings`)
            SQL, $bindings);
    }

    /**
     * A `{SSHA256}` hash of a random password with a random salt, which nobody knows.
     */
    private function placeholderHash(): string
    {
        $salt = random_bytes(16);

        return '{SSHA256}'.base64_encode(hash('sha256', random_bytes(24).$salt, true).$salt);
    }
}
