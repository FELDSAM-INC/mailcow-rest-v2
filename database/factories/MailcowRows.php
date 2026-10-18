<?php

namespace Database\Factories;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

/**
 * Inserts upstream mailcow rows exactly as legacy writes them (research R3), for the tests and
 * the development seeder. Column lists and value formats follow mailcow-dockerized 2026-09a:
 * `domain_admin('add')`, `mailbox('add', 'mailbox')`, `set_tfa()` and `fido2('register')`.
 * Upstream timestamps are left to their NOW() defaults (mailcow local time).
 *
 * This is not a service: production code never writes upstream tables outside app/Services.
 */
class MailcowRows
{
    /** Default attributes of admin rows after init_db_schema()'s attribute migration. */
    private const ADMIN_ATTRIBUTES = ['force_tfa' => '0', 'force_pw_update' => '0'];

    /** The Default domain template of init_db_schema() (2026-09a), in its key order. */
    public const DEFAULT_DOMAIN_TEMPLATE = [
        'tags' => [],
        'max_num_aliases_for_domain' => 400,
        'max_num_mboxes_for_domain' => 10,
        'def_quota_for_mbox' => 3072 * 1048576,
        'max_quota_for_mbox' => 10240 * 1048576,
        'max_quota_for_domain' => 10240 * 1048576,
        'rl_frame' => 's',
        'rl_value' => '',
        'active' => 1,
        'gal' => 1,
        'backupmx' => 0,
        'relay_all_recipients' => 0,
        'relay_unknown_only' => 0,
        'dkim_selector' => 'dkim',
        'key_size' => 2048,
    ];

    /**
     * The Default mailbox template of init_db_schema() (2026-09a) with upstream
     * $MAILBOX_DEFAULT_ATTRIBUTES, in its key order. It lacks `tagged_mail_handler` and
     * `acl_pw_reset`, as upstream seeds it.
     */
    public const DEFAULT_MAILBOX_TEMPLATE = [
        'tags' => [],
        'quota' => 0,
        'quarantine_notification' => 'hourly',
        'quarantine_category' => 'reject',
        'rl_frame' => 's',
        'rl_value' => '',
        'force_pw_update' => 0,
        'force_tfa' => 0,
        'sogo_access' => 1,
        'active' => 1,
        'tls_enforce_in' => 0,
        'tls_enforce_out' => 0,
        'imap_access' => 1,
        'pop3_access' => 1,
        'smtp_access' => 1,
        'sieve_access' => 1,
        'eas_access' => 1,
        'dav_access' => 1,
        'acl_spam_alias' => 1,
        'acl_tls_policy' => 1,
        'acl_spam_score' => 1,
        'acl_spam_policy' => 1,
        'acl_delimiter_action' => 1,
        'acl_syncjobs' => 0,
        'acl_eas_reset' => 1,
        'acl_sogo_profile_reset' => 0,
        'acl_pushover' => 1,
        'acl_quarantine' => 1,
        'acl_quarantine_attachments' => 1,
        'acl_quarantine_notification' => 1,
        'acl_quarantine_category' => 1,
        'acl_app_passwds' => 1,
    ];

    /**
     * A superadmin (`admin.superadmin = 1`).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function admin(string $username, string $password, string $scheme = 'BLF-CRYPT', int $active = 1, array $attributes = []): void
    {
        DB::table('admin')->insert([
            'username' => $username,
            'password' => self::hash($scheme, $password),
            'superadmin' => 1,
            'active' => $active,
            'attributes' => json_encode(array_merge(self::ADMIN_ATTRIBUTES, $attributes)),
        ]);
    }

    /**
     * A domain administrator: `admin` with `superadmin = 0`, one `domain_admins` row per domain
     * and a `da_acl` row (column defaults, overridden by $daAcl).
     *
     * $assignmentActive sets `domain_admins.active` separately from the account's `active`.
     *
     * @param  list<string>  $domains
     * @param  array<string, int|bool>  $daAcl
     * @param  array<string, mixed>  $attributes
     */
    public function domainAdmin(
        string $username,
        array $domains,
        array $daAcl = [],
        int $active = 1,
        string $password = 'Passw0rd!',
        string $scheme = 'BLF-CRYPT',
        array $attributes = [],
        ?int $assignmentActive = null,
    ): void {
        foreach ($domains as $domain) {
            DB::table('domain_admins')->insert([
                'username' => $username,
                'domain' => $domain,
                'created' => DB::raw('NOW()'),
                'active' => $assignmentActive ?? $active,
            ]);
        }

        DB::table('admin')->insert([
            'username' => $username,
            'password' => self::hash($scheme, $password),
            'superadmin' => 0,
            'active' => $active,
            'attributes' => json_encode(array_merge(self::ADMIN_ATTRIBUTES, $attributes)),
        ]);

        DB::table('da_acl')->insert(['username' => $username] + array_map(fn (int|bool $v): int => (int) $v, $daAcl));
    }

    /**
     * A domain as `mailbox('add', 'domain')` stores it from the seeded Default template. Quotas in
     * $attributes are MiB, as in the table; any column may be overridden (`relayhost`, `created`,
     * `modified`, ...).
     *
     * @param  array<string, mixed>  $attributes
     */
    public function domain(string $name, int $active = 1, array $attributes = []): void
    {
        DB::table('domain')->insert(array_merge([
            'domain' => $name,
            'description' => $name,
            'aliases' => 400,
            'mailboxes' => 10,
            'defquota' => 3072,
            'maxquota' => 10240,
            'quota' => 10240,
            'backupmx' => 0,
            'gal' => 1,
            'active' => $active,
            'relay_unknown_only' => 0,
            'relay_all_recipients' => 0,
        ], $attributes));
    }

    /**
     * Tags of a domain (`tags_domain`), as legacy inserts them one row per tag.
     *
     * @param  list<string>  $tags
     */
    public function domainTags(string $domain, array $tags): void
    {
        foreach ($tags as $tag) {
            DB::table('tags_domain')->insert(['domain' => $domain, 'tag_name' => $tag]);
        }
    }

    /**
     * A template row (`templates`) with the attributes JSON exactly as given (legacy keys; domain
     * template quotas in bytes). Returns its ID.
     *
     * @param  array<string, mixed>|string  $attributes  an array is JSON-encoded, a string stored as is
     */
    public function domainTemplate(string $name, array|string $attributes = [], string $type = 'domain'): int
    {
        return (int) DB::table('templates')->insertGetId([
            'template' => $name,
            'type' => $type,
            'attributes' => is_string($attributes) ? $attributes : json_encode($attributes),
        ]);
    }

    /**
     * The Default domain template exactly as init_db_schema() of 2026-09a seeds it. Returns its ID.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function defaultDomainTemplate(array $overrides = []): int
    {
        return $this->domainTemplate('Default', array_merge(self::DEFAULT_DOMAIN_TEMPLATE, $overrides));
    }

    /**
     * A domain-wide footer (`mailbox('edit', 'domain_wide_footer')`).
     *
     * @param  list<string>  $mboxExclude
     * @param  list<string>  $aliasDomainExclude
     */
    public function domainFooter(
        string $domain,
        ?string $html = '',
        ?string $plain = '',
        array $mboxExclude = [],
        array $aliasDomainExclude = [],
        int $skipReplies = 0,
    ): void {
        DB::table('domain_wide_footer')->insert([
            'domain' => $domain,
            'html' => $html,
            'plain' => $plain,
            'mbox_exclude' => json_encode($mboxExclude),
            'alias_domain_exclude' => json_encode($aliasDomainExclude),
            'skip_replies' => $skipReplies,
        ]);
    }

    /** An alias domain (`mailbox('add', 'alias_domain')`). */
    public function aliasDomain(string $alias, string $target, int $active = 1): void
    {
        DB::table('alias_domain')->insert([
            'alias_domain' => $alias,
            'target_domain' => $target,
            'active' => $active,
        ]);
    }

    /**
     * An alias with legacy's column list (`mailbox('add', 'alias')`); the domain defaults to the
     * address's domain part. Comments default to NULL, as rows written before the comment fields
     * existed. Returns its ID.
     */
    public function alias(
        string $address,
        string $goto,
        ?string $domain = null,
        int $active = 1,
        int $senderAllowed = 1,
        int $sogoVisible = 1,
        int $internal = 0,
        ?string $publicComment = null,
        ?string $privateComment = null,
    ): int {
        return (int) DB::table('alias')->insertGetId([
            'address' => $address,
            'goto' => $goto,
            'domain' => $domain ?? substr((string) strrchr($address, '@'), 1),
            'active' => $active,
            'sogo_visible' => $sogoVisible,
            'internal' => $internal,
            'sender_allowed' => $senderAllowed,
            'public_comment' => $publicComment,
            'private_comment' => $privateComment,
        ]);
    }

    /**
     * A groupware resource exactly as `mailbox('add', 'resource')` stores it: a `mailbox` row with
     * the password `RESOURCE`, quota 0, the kind and the booking rule, and no `user_acl` row.
     */
    public function resource(string $address, string $description, string $kind = 'location', int $multipleBookings = -1, int $active = 1): void
    {
        [$localPart, $domain] = explode('@', $address, 2) + [1 => ''];

        if ($domain === '') {
            throw new InvalidArgumentException("{$address} is not an e-mail address");
        }

        DB::table('mailbox')->insert([
            'username' => $address,
            'password' => 'RESOURCE',
            'name' => $description,
            'quota' => 0,
            'local_part' => $localPart,
            'domain' => $domain,
            'active' => $active,
            'multiple_bookings' => $multipleBookings,
            'kind' => $kind,
        ]);
    }

    /** Stored usage of a mailbox (`quota2`, maintained by Dovecot). */
    public function quota2(string $username, int $bytes, int $messages): void
    {
        DB::table('quota2')->insert(['username' => $username, 'bytes' => $bytes, 'messages' => $messages]);
    }

    /** Replicated usage of a mailbox (`quota2replica`). */
    public function quota2replica(string $username, int $bytes = 0, int $messages = 0): void
    {
        DB::table('quota2replica')->insert(['username' => $username, 'bytes' => $bytes, 'messages' => $messages]);
    }

    /** A relayhost (`relayhosts`, feature 009). */
    public function relayhost(int $id, string $hostname): void
    {
        DB::table('relayhosts')->insert(['id' => $id, 'hostname' => $hostname, 'username' => '', 'password' => '', 'active' => 1]);
    }

    /** A sender permission (`sender_acl`). Returns its ID. */
    public function senderAcl(string $loggedInAs, string $sendAs, int $external = 0): int
    {
        return (int) DB::table('sender_acl')->insertGetId(['logged_in_as' => $loggedInAs, 'send_as' => $sendAs, 'external' => $external]);
    }

    /** A temporary alias (`spamalias`). */
    public function spamalias(string $address, string $goto): void
    {
        DB::table('spamalias')->insert(['address' => $address, 'goto' => $goto, 'description' => '', 'validity' => time() + 3600]);
    }

    /** A spam filter entry (`filterconf`, for example `whitelist_from`). */
    public function filterconf(string $object, string $option, string $value): void
    {
        DB::table('filterconf')->insert(['object' => $object, 'option' => $option, 'value' => $value]);
    }

    /** A BCC map (`bcc_maps`); `local_dest` is `@domain` for domain maps, as legacy stores them. */
    public function bccMap(string $localDest, string $bccDest, string $domain, string $type = 'rcpt'): int
    {
        return (int) DB::table('bcc_maps')->insertGetId([
            'local_dest' => $localDest,
            'bcc_dest' => $bccDest,
            'domain' => $domain,
            'type' => $type,
            'active' => 1,
        ]);
    }

    /** An MTA-STS policy (`mta_sts`), as legacy stores it. */
    public function mtaSts(string $domain): void
    {
        DB::table('mta_sts')->insert([
            'id' => (int) date('YmdHis'),
            'domain' => $domain,
            'version' => 'stsv1',
            'mode' => 'enforce',
            'mx' => 'mail.example.org',
            'max_age' => '86400',
            'active' => 1,
        ]);
    }

    /** Pushover settings of a mailbox (`pushover`). */
    public function pushover(string $username, int $active = 1): void
    {
        DB::table('pushover')->insert(['username' => $username, 'key' => str_repeat('k', 30), 'token' => str_repeat('t', 30), 'attributes' => '{}', 'active' => $active]);
    }

    /**
     * A domain administrator account without any assignment (`admin` with `superadmin = 0`), as
     * left behind by an earlier legacy change.
     */
    public function orphanedDomainAdmin(string $username): void
    {
        DB::table('admin')->insert([
            'username' => $username,
            'password' => self::hash('BLF-CRYPT', 'Passw0rd!'),
            'superadmin' => 0,
            'active' => 1,
            'attributes' => json_encode(self::ADMIN_ATTRIBUTES),
        ]);
        DB::table('da_acl')->insert(['username' => $username]);
    }

    /**
     * A mailbox and, for an ordinary mailbox (empty kind), its `user_acl` row (column defaults,
     * overridden by $userAcl). The domain must exist. `quota` is bytes (0 = unlimited); a resource
     * has the kind `location`, `thing` or `group`.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int|bool>  $userAcl
     */
    public function mailbox(
        string $username,
        string $password = 'Passw0rd!',
        string $scheme = 'BLF-CRYPT',
        int $active = 1,
        string $kind = '',
        string $authsource = 'mailcow',
        array $attributes = [],
        array $userAcl = [],
        int $quota = 0,
    ): void {
        [$localPart, $domain] = explode('@', $username, 2) + [1 => ''];

        if ($domain === '') {
            throw new InvalidArgumentException("{$username} is not an e-mail address");
        }

        DB::table('mailbox')->insert([
            'username' => $username,
            'password' => self::hash($scheme, $password),
            'name' => $localPart,
            'quota' => $quota,
            'local_part' => $localPart,
            'domain' => $domain,
            'attributes' => json_encode(array_merge([
                'force_pw_update' => '0',
                'force_tfa' => '0',
                'tls_enforce_in' => '0',
                'tls_enforce_out' => '0',
                'sogo_access' => '1',
                'imap_access' => '1',
                'pop3_access' => '1',
                'smtp_access' => '1',
                'sieve_access' => '1',
                'eas_access' => '1',
                'dav_access' => '1',
                'relayhost' => '0',
                'passwd_update' => time(),
                'mailbox_format' => 'maildir:',
                'quarantine_notification' => 'hourly',
                'quarantine_category' => 'reject',
            ], $attributes)),
            'kind' => $kind,
            'authsource' => $authsource,
            'active' => $active,
        ]);

        if ($kind === '') {
            DB::table('user_acl')->insert(['username' => $username] + array_map(fn (int|bool $v): int => (int) $v, $userAcl));
        }
    }

    /** A TOTP second factor (`set_tfa` case totp). Returns `tfa.id`. */
    public function tfaTotp(string $username, string $secret, string $keyId = 'Phone', int $active = 1): int
    {
        return (int) DB::table('tfa')->insertGetId([
            'username' => $username,
            'key_id' => $keyId,
            'authmech' => 'totp',
            'secret' => $secret,
            'active' => $active,
        ]);
    }

    /**
     * A WebAuthn second factor (`set_tfa` case webauthn): `keyHandle` is the standard base64 of
     * the raw credential ID, `publicKey` the PEM key. Returns `tfa.id`.
     */
    public function tfaWebAuthn(string $username, string $credentialId, string $publicKeyPem, string $keyId = 'Security key', int $active = 1): int
    {
        return (int) DB::table('tfa')->insertGetId([
            'username' => $username,
            'key_id' => $keyId,
            'authmech' => 'webauthn',
            'keyHandle' => base64_encode($credentialId),
            'publicKey' => $publicKeyPem,
            'certificate' => null,
            'counter' => 0,
            'active' => $active,
        ]);
    }

    /**
     * A Yubico OTP second factor (`set_tfa` case yubi_otp): secret
     * `<client id>:<base64 key>:<modhex public id>`. Returns `tfa.id`.
     */
    public function tfaYubi(string $username, string $clientId, string $key, string $modhex, string $keyId = 'YubiKey', int $active = 1): int
    {
        return (int) DB::table('tfa')->insertGetId([
            'username' => $username,
            'key_id' => $keyId,
            'authmech' => 'yubi_otp',
            'secret' => $clientId.':'.$key.':'.$modhex,
            'active' => $active,
        ]);
    }

    /** A legacy U2F row (no longer usable for sign-in). Returns `tfa.id`. */
    public function tfaU2f(string $username, int $active = 1): int
    {
        return (int) DB::table('tfa')->insertGetId([
            'username' => $username,
            'key_id' => 'U2F key',
            'authmech' => 'u2f',
            'keyHandle' => base64_encode(random_bytes(32)),
            'publicKey' => base64_encode(random_bytes(65)),
            'active' => $active,
        ]);
    }

    /** A legacy HOTP row (never offered). Returns `tfa.id`. */
    public function tfaHotp(string $username, int $active = 1): int
    {
        return (int) DB::table('tfa')->insertGetId([
            'username' => $username,
            'key_id' => 'HOTP',
            'authmech' => 'hotp',
            'secret' => 'JBSWY3DPEHPK3PXP',
            'active' => $active,
        ]);
    }

    /**
     * A passkey (`fido2('register')`): raw credential ID bytes, PEM public key, the relying
     * party ID and the raw 16-byte AAGUID. `friendlyName` is NULL unless given.
     */
    public function fido2(
        string $username,
        string $credentialId,
        string $publicKeyPem,
        string $rpId,
        ?string $aaguid = null,
        ?string $friendlyName = null,
        int $active = 1,
        ?string $certificateSubject = null,
        ?int $signatureCounter = null,
    ): void {
        DB::table('fido2')->insert([
            'username' => $username,
            'friendlyName' => $friendlyName,
            'rpId' => $rpId,
            'credentialPublicKey' => $publicKeyPem,
            'certificateChain' => null,
            'certificate' => null,
            'certificateIssuer' => null,
            'certificateSubject' => $certificateSubject,
            'signatureCounter' => $signatureCounter,
            'AAGUID' => $aaguid ?? str_repeat("\0", 16),
            'credentialId' => $credentialId,
            'active' => $active,
        ]);
    }

    /**
     * A mailbox exactly as legacy `mailbox('add', 'mailbox')` stores it (feature 003 research R25):
     * the row with the string attribute set (and `passwd_update` as `NOW()`), zeroed `quota2` and
     * `quota2replica` rows, the own alias with the mailbox's status, the `user_acl` row (column
     * defaults, overridden by $userAcl) and one `tags_mailbox` row per tag. The domain must exist.
     * `quota` is bytes (0 = unlimited).
     *
     * @param  array<string, mixed>  $attributes  overrides of the attribute set (null removes a key)
     * @param  array<string, int|bool>  $userAcl
     * @param  list<string>  $tags
     * @param  array<string, string>  $customAttributes
     */
    public function legacyMailbox(
        string $username,
        int $active = 1,
        int $quota = 0,
        array $attributes = [],
        array $userAcl = [],
        array $tags = [],
        string $authsource = 'mailcow',
        array $customAttributes = [],
        ?string $name = null,
        string $password = 'Passw0rd!',
        string $scheme = 'BLF-CRYPT',
    ): void {
        [$localPart, $domain] = explode('@', $username, 2) + [1 => ''];

        if ($domain === '') {
            throw new InvalidArgumentException("{$username} is not an e-mail address");
        }

        $set = array_merge([
            'force_pw_update' => '0',
            'force_tfa' => '0',
            'tls_enforce_in' => '0',
            'tls_enforce_out' => '0',
            'sogo_access' => '1',
            'imap_access' => '1',
            'pop3_access' => '1',
            'smtp_access' => '1',
            'sieve_access' => '1',
            'eas_access' => '1',
            'dav_access' => '1',
            'relayhost' => '0',
            'passwd_update' => time(),
            'mailbox_format' => 'maildir:',
            'quarantine_notification' => 'hourly',
            'quarantine_category' => 'reject',
            'attribute_hash' => '',
        ], $attributes);
        $set = array_filter($set, fn (mixed $value): bool => $value !== null);

        DB::table('mailbox')->insert([
            'username' => $username,
            'password' => $authsource === 'mailcow' ? self::hash($scheme, $password) : '',
            'name' => $name ?? $localPart,
            'quota' => $quota,
            'local_part' => $localPart,
            'domain' => $domain,
            'attributes' => json_encode($set),
            'custom_attributes' => json_encode((object) $customAttributes),
            'authsource' => $authsource,
            'active' => $active,
        ]);

        if (! array_key_exists('passwd_update', $attributes)) {
            DB::update("UPDATE `mailbox` SET `attributes` = JSON_SET(`attributes`, '$.passwd_update', NOW()) WHERE `username` = ?", [$username]);
        }

        foreach ($tags as $tag) {
            $this->mailboxTag($username, $tag);
        }

        DB::table('quota2')->insert(['username' => $username, 'bytes' => 0, 'messages' => 0]);
        DB::table('quota2replica')->insert(['username' => $username, 'bytes' => 0, 'messages' => 0]);
        DB::table('alias')->insert(['address' => $username, 'goto' => $username, 'domain' => $domain, 'active' => $active]);
        DB::table('user_acl')->insert(['username' => $username] + array_map(fn (int|bool $v): int => (int) $v, $userAcl));
    }

    /** A tag of a mailbox (`tags_mailbox`). */
    public function mailboxTag(string $username, string $tag): void
    {
        DB::table('tags_mailbox')->insert(['username' => $username, 'tag_name' => $tag]);
    }

    /** Stored usage of a mailbox, replacing its row (`quota2`, or `quota2replica` for a replica). */
    public function usage(string $username, int $bytes, int $messages, bool $replica = false): void
    {
        DB::table($replica ? 'quota2replica' : 'quota2')->updateOrInsert(['username' => $username], ['bytes' => $bytes, 'messages' => $messages]);
    }

    /**
     * A mailbox template (`templates` with `type = 'mailbox'`), attributes exactly as given (legacy
     * keys, quota in bytes). Returns its ID.
     *
     * @param  array<string, mixed>|string  $attributes
     */
    public function mailboxTemplate(string $name, array|string $attributes = []): int
    {
        return $this->domainTemplate($name, $attributes, 'mailbox');
    }

    /**
     * The Default mailbox template exactly as init_db_schema() of 2026-09a seeds it. Returns its ID.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function defaultMailboxTemplate(array $overrides = []): int
    {
        return $this->mailboxTemplate('Default', array_merge(self::DEFAULT_MAILBOX_TEMPLATE, $overrides));
    }

    /** A temporary alias (`spamalias`) with an explicit validity (Unix time) and permanence. */
    public function temporaryAlias(string $address, string $goto, ?int $validity = null, int $permanent = 0): void
    {
        DB::table('spamalias')->insert(['address' => $address, 'goto' => $goto, 'description' => '', 'validity' => $validity ?? time() + 3600, 'permanent' => $permanent]);
    }

    /**
     * A sync job delivering into a mailbox (`imapsync`) exactly as legacy's mailbox('add',
     * 'syncjob') inserts it from the "Add sync job" form's defaults (feature 008 data-model.md):
     * legacy's column list, `authmech1 = 'PLAIN'`, `maxbytespersecond` as a decimal string. Any
     * column may be overridden, the runner's status columns included. Returns its ID.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function syncJob(string $user2, array $overrides = []): int
    {
        return (int) DB::table('imapsync')->insertGetId(array_merge([
            'user2' => $user2,
            'exclude' => '(?i)spam|(?i)junk',
            'delete1' => 0,
            'delete2' => 0,
            'timeout1' => 600,
            'timeout2' => 600,
            'automap' => 1,
            'skipcrossduplicates' => 0,
            'maxbytespersecond' => '0',
            'subscribeall' => 1,
            'dry' => 0,
            'maxage' => 0,
            'subfolder2' => '',
            'host1' => 'imap.example.net',
            'authmech1' => 'PLAIN',
            'user1' => 'remote@example.net',
            'password1' => 'remote-secret',
            'mins_interval' => 20,
            'port1' => 993,
            'enc1' => 'SSL',
            'delete2duplicates' => 1,
            'custom_params' => '',
            'active' => 1,
        ], $overrides));
    }

    /** A quarantine item for a recipient (`quarantine`). Returns its ID. */
    public function quarantineItem(string $rcpt): int
    {
        return (int) DB::table('quarantine')->insertGetId([
            'qid' => strtoupper(bin2hex(random_bytes(5))),
            'subject' => 'Spam',
            'score' => 15.5,
            'action' => 'reject',
            'sender' => 'spammer@example.net',
            'rcpt' => $rcpt,
            'msg' => 'Subject: Spam',
            'domain' => substr((string) strrchr($rcpt, '@'), 1),
        ]);
    }

    /** A recipient map (`recipient_maps`). Returns its ID. */
    public function recipientMap(string $oldDest, string $newDest): int
    {
        return (int) DB::table('recipient_maps')->insertGetId(['old_dest' => $oldDest, 'new_dest' => $newDest, 'active' => 1]);
    }

    /** A Sieve filter of a mailbox (`sieve_filters`). Returns its ID. */
    public function sieveFilter(string $username, string $type = 'prefilter'): int
    {
        return (int) DB::table('sieve_filters')->insertGetId([
            'username' => $username,
            'script_desc' => 'Filter '.bin2hex(random_bytes(3)),
            'script_name' => 'active',
            'script_data' => 'keep;',
            'filter_type' => $type,
        ]);
    }

    /** An app password of a mailbox (`app_passwd`). Returns its ID. */
    public function appPassword(string $mailbox, string $name = 'Phone', string $password = 'AppPassw0rd!'): int
    {
        return (int) DB::table('app_passwd')->insertGetId([
            'name' => $name,
            'mailbox' => $mailbox,
            'domain' => substr((string) strrchr($mailbox, '@'), 1),
            'password' => self::hash('BLF-CRYPT', $password),
        ]);
    }

    /**
     * Webmail data of a user as SOGo stores it: profile, cache folder, a calendar and an address
     * book (`sogo_folder_info`) with a stored event, an appointment and a contact, and a sharing
     * permission on the calendar.
     */
    public function sogoData(string $username): void
    {
        DB::table('sogo_user_profile')->insert(['c_uid' => $username, 'c_defaults' => '{}', 'c_settings' => '{}']);
        DB::table('sogo_cache_folder')->insert(['c_uid' => $username, 'c_path' => '/'.$username.'/Calendar', 'c_type' => 0, 'c_creationdate' => time(), 'c_lastmodified' => time()]);

        foreach (['Calendar' => 'Appointment', 'Contacts' => 'Contact'] as $folder => $type) {
            $id = (int) DB::table('sogo_folder_info')->insertGetId([
                'c_path' => '/Users/'.$username.'/'.$folder.'/personal',
                'c_path1' => 'Users',
                'c_path2' => $username,
                'c_path3' => $folder,
                'c_path4' => 'personal',
                'c_foldername' => $folder,
                'c_folder_type' => $type,
            ], 'c_folder_id');

            DB::table('sogo_store')->insert(['c_folder_id' => $id, 'c_name' => 'item.ics', 'c_content' => 'BEGIN:VCALENDAR', 'c_creationdate' => time(), 'c_lastmodified' => time(), 'c_version' => 0]);

            if ($folder === 'Calendar') {
                DB::table('sogo_quick_appointment')->insert(['c_folder_id' => $id, 'c_name' => 'item.ics', 'c_uid' => 'uid-1', 'c_title' => 'Meeting', 'c_classification' => 0, 'c_isopaque' => 1, 'c_status' => 1, 'c_component' => 'vevent']);
                DB::table('sogo_acl')->insert(['c_folder_id' => $id, 'c_object' => '/Users/'.$username.'/Calendar/personal', 'c_uid' => 'colleague@example.net', 'c_role' => 'ObjectViewer']);
            } else {
                DB::table('sogo_quick_contact')->insert(['c_folder_id' => $id, 'c_name' => 'item.vcf', 'c_cn' => 'Friend', 'c_mail' => 'friend@example.net', 'c_component' => 'vcard']);
            }
        }
    }

    /** OAuth access and refresh tokens and an authorisation code of a user (`oauth_*`). */
    public function oauthTokens(string $userId): void
    {
        $expires = date('Y-m-d H:i:s', time() + 3600);
        DB::table('oauth_access_tokens')->insert(['access_token' => bin2hex(random_bytes(20)), 'client_id' => 'client', 'user_id' => $userId, 'expires' => $expires]);
        DB::table('oauth_refresh_tokens')->insert(['refresh_token' => bin2hex(random_bytes(20)), 'client_id' => 'client', 'user_id' => $userId, 'expires' => $expires]);
        DB::table('oauth_authorization_codes')->insert(['authorization_code' => bin2hex(random_bytes(20)), 'client_id' => 'client', 'user_id' => $userId, 'expires' => $expires]);
    }

    /** A recent-login record (`sasl_log`); `datetime` is mailcow local time. */
    public function saslLog(string $username, string $service, string $datetime, string $realRip = '192.0.2.10'): void
    {
        DB::table('sasl_log')->insert(['username' => $username, 'service' => $service, 'datetime' => $datetime, 'real_rip' => $realRip]);
    }

    /** The configured external identity provider (`identity_provider.authsource`). */
    public function identityProvider(string $authsource): void
    {
        DB::table('identity_provider')->updateOrInsert(['key' => 'authsource'], ['value' => $authsource]);
    }

    /**
     * Raw `identity_provider` rows (key => value), upserted as given: for configurations that only
     * legacy can store (empty values, rows of another type, legacy JSON objects).
     *
     * @param  array<string, string>  $keyValues
     */
    public function identityProviderRows(array $keyValues): void
    {
        foreach ($keyValues as $key => $value) {
            DB::table('identity_provider')->updateOrInsert(['key' => $key], ['value' => $value]);
        }
    }

    /** The bind password of ldapConfig(). */
    public const LDAP_BIND_PASSWORD = 'ldap-secret';

    /** The client secret of keycloakConfig(). */
    public const KEYCLOAK_CLIENT_SECRET = 'kc-secret';

    /** The client secret of genericOidcConfig(). */
    public const OIDC_CLIENT_SECRET = 'oidc-secret';

    /**
     * A complete LDAP configuration with every key legacy identity_provider('edit') writes, in its
     * encodings (PHP booleans bound as '1'/'', integers as decimal strings, JSON lists). Overrides
     * use the legacy keys.
     *
     * @param  array<string, string>  $overrides
     */
    public function ldapConfig(array $overrides = []): void
    {
        $this->identityProviderRows(array_merge([
            'authsource' => 'ldap',
            'host' => 'ldap1.example.org,ldap2.example.org',
            'port' => '389',
            'basedn' => 'ou=people,dc=example,dc=org',
            'username_field' => 'mail',
            'filter' => '(objectClass=inetOrgPerson)',
            'attribute_field' => 'title',
            'binddn' => 'cn=admin,dc=example,dc=org',
            'bindpass' => self::LDAP_BIND_PASSWORD,
            'periodic_sync' => '0',
            'import_users' => '0',
            'sync_interval' => '15',
            'use_ssl' => '',
            'use_tls' => '',
            'ignore_ssl_error' => '',
            'login_provisioning' => '1',
            'mappers' => '["staff"]',
            'templates' => '["Staff"]',
            'default_template' => '',
            'access_token' => '',
        ], $overrides));
    }

    /**
     * A complete Keycloak configuration, as ldapConfig() (version 26.0.7, realm `example`).
     *
     * @param  array<string, string>  $overrides
     */
    public function keycloakConfig(array $overrides = []): void
    {
        $this->identityProviderRows(array_merge([
            'authsource' => 'keycloak',
            'server_url' => 'https://sso.example.org',
            'realm' => 'example',
            'client_id' => 'mailcow',
            'client_secret' => self::KEYCLOAK_CLIENT_SECRET,
            'redirect_url' => 'https://mail.example.org',
            'redirect_url_extra' => '[]',
            'version' => '26.0.7',
            'mailpassword_flow' => '0',
            'periodic_sync' => '0',
            'import_users' => '0',
            'sync_interval' => '15',
            'ignore_ssl_error' => '',
            'login_provisioning' => '1',
            'mappers' => '["staff"]',
            'templates' => '["Staff"]',
            'default_template' => '',
            'access_token' => '',
        ], $overrides));
    }

    /**
     * A complete generic OIDC configuration, as ldapConfig().
     *
     * @param  array<string, string>  $overrides
     */
    public function genericOidcConfig(array $overrides = []): void
    {
        $this->identityProviderRows(array_merge([
            'authsource' => 'generic-oidc',
            'authorize_url' => 'https://idp.example.net/oauth2/authorize',
            'token_url' => 'https://idp.example.net/oauth2/token',
            'userinfo_url' => 'https://idp.example.net/oauth2/userinfo',
            'client_id' => 'mailcow',
            'client_secret' => self::OIDC_CLIENT_SECRET,
            'redirect_url' => 'https://mail.example.org',
            'redirect_url_extra' => '[]',
            'client_scopes' => 'openid profile email mailcow_template',
            'ignore_ssl_error' => '',
            'login_provisioning' => '1',
            'mappers' => '["staff"]',
            'templates' => '["Staff"]',
            'default_template' => '',
            'access_token' => '',
        ], $overrides));
    }

    /**
     * An OAuth2 client as legacy oauth2('add', 'client') inserts it: generated client ID (12 hex)
     * and secret (24 hex), scope `profile`, `grant_types` and `user_id` NULL. Returns its `id`.
     */
    public function oauthClient(string $redirectUri, ?string $clientId = null, ?string $secret = null): int
    {
        return (int) DB::table('oauth_clients')->insertGetId([
            'client_id' => $clientId ?? bin2hex(random_bytes(6)),
            'client_secret' => $secret ?? bin2hex(random_bytes(12)),
            'redirect_uri' => $redirectUri,
            'grant_types' => null,
            'scope' => 'profile',
            'user_id' => null,
        ], 'id');
    }

    /** An access token of a client (`oauth_access_tokens`); a string `expires` is mailcow local time. Returns the token. */
    public function oauthAccessToken(string $clientId, DateTimeInterface|string $expires, string $userId = 'user@example.org'): string
    {
        return $this->oauthRow('oauth_access_tokens', 'access_token', $clientId, $expires, $userId);
    }

    /** A refresh token of a client (`oauth_refresh_tokens`). Returns the token. */
    public function oauthRefreshToken(string $clientId, DateTimeInterface|string $expires, string $userId = 'user@example.org'): string
    {
        return $this->oauthRow('oauth_refresh_tokens', 'refresh_token', $clientId, $expires, $userId);
    }

    /** An authorization code of a client (`oauth_authorization_codes`). Returns the code. */
    public function oauthAuthorizationCode(string $clientId, DateTimeInterface|string $expires, string $userId = 'user@example.org'): string
    {
        return $this->oauthRow('oauth_authorization_codes', 'authorization_code', $clientId, $expires, $userId);
    }

    /**
     * Entries of mailcow's scheduled-job log (Redis list `CRON_LOG` on the `mailcow` connection),
     * pushed with LPUSH as the legacy jobs do, so the last entry given is the newest.
     *
     * @param  list<array{time: int, priority: string, task: string, message: string}>  $entries  oldest first
     */
    public function cronLog(array $entries): void
    {
        $redis = Redis::connection('mailcow');

        foreach ($entries as $entry) {
            $redis->lpush('CRON_LOG', json_encode($entry, JSON_THROW_ON_ERROR));
        }
    }

    private function oauthRow(string $table, string $column, string $clientId, DateTimeInterface|string $expires, string $userId): string
    {
        $value = bin2hex(random_bytes(20));
        $local = $expires instanceof DateTimeInterface
            ? CarbonImmutable::instance($expires)->setTimezone((string) (config('restv2.timezone') ?: 'UTC'))->format('Y-m-d H:i:s')
            : $expires;

        DB::table($table)->insert([$column => $value, 'client_id' => $clientId, 'user_id' => $userId, 'expires' => $local]);

        return $value;
    }

    /**
     * A password hash in `{SCHEME}hash` form, as Dovecot and legacy `hash_password()` write it.
     */
    public static function hash(string $scheme, string $password): string
    {
        $scheme = strtoupper($scheme);
        $salt = bin2hex(random_bytes(8));

        $hash = match ($scheme) {
            'BLF-CRYPT' => password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]),
            'ARGON2I' => password_hash($password, PASSWORD_ARGON2I),
            'ARGON2ID' => password_hash($password, PASSWORD_ARGON2ID),
            'SHA512-CRYPT' => crypt($password, '$6$'.substr($salt, 0, 16).'$'),
            'SHA256-CRYPT' => crypt($password, '$5$'.substr($salt, 0, 16).'$'),
            'MD5-CRYPT' => crypt($password, '$1$'.substr($salt, 0, 8).'$'),
            'SSHA' => base64_encode(hash('sha1', $password.$salt, true).$salt),
            'SSHA256' => base64_encode(hash('sha256', $password.$salt, true).$salt),
            'SSHA512' => base64_encode(hash('sha512', $password.$salt, true).$salt),
            'SMD5' => base64_encode(hash('md5', $password.$salt, true).$salt),
            'SHA', 'SHA1' => base64_encode(hash('sha1', $password, true)),
            'SHA256' => base64_encode(hash('sha256', $password, true)),
            'SHA512' => base64_encode(hash('sha512', $password, true)),
            'LDAP-MD5' => base64_encode(hash('md5', $password, true)),
            'PLAIN-MD5' => md5($password),
            'PLAIN-MD4' => hash('md4', $password),
            'PLAIN', 'CLEAR', 'CLEARTEXT' => $password,
            default => throw new InvalidArgumentException("Unsupported password scheme {$scheme}"),
        };

        return '{'.$scheme.'}'.$hash;
    }
}
