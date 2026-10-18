<?php

namespace Database\Factories;

use Illuminate\Support\Facades\DB;
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
    ): void {
        foreach ($domains as $domain) {
            DB::table('domain_admins')->insert([
                'username' => $username,
                'domain' => $domain,
                'created' => DB::raw('NOW()'),
                'active' => $active,
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

    public function domain(string $name, int $active = 1): void
    {
        DB::table('domain')->insert([
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
        ]);
    }

    /**
     * A mailbox and, for an ordinary mailbox (empty kind), its `user_acl` row (column defaults,
     * overridden by $userAcl). The domain must exist.
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
    ): void {
        [$localPart, $domain] = explode('@', $username, 2) + [1 => ''];

        if ($domain === '') {
            throw new InvalidArgumentException("{$username} is not an e-mail address");
        }

        DB::table('mailbox')->insert([
            'username' => $username,
            'password' => self::hash($scheme, $password),
            'name' => $localPart,
            'quota' => 0,
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

    /** The configured external identity provider (`identity_provider.authsource`). */
    public function identityProvider(string $authsource): void
    {
        DB::table('identity_provider')->updateOrInsert(['key' => 'authsource'], ['value' => $authsource]);
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
