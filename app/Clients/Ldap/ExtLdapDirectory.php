<?php

namespace App\Clients\Ldap;

use LDAP\Connection;

/**
 * LdapDirectory on the `ldap` PHP extension (research R2), reproducing what legacy's LdapRecord does
 * for mailcow:
 *
 *  - `ldap://host:port` or, with SSL, `ldaps://host:port` where 389 becomes 636; several hosts are
 *    one URI list tried in order; IPv6 literals are bracketed;
 *  - protocol 3, no referrals, network time-out and time limit of `restv2.identity_provider.timeout`
 *    seconds (FR-056); with ignored TLS errors the certificate is not required;
 *  - StartTLS when configured (never together with SSL);
 *  - the user is searched with `(&<filter>(<field>=<escaped username>))` (size limit 1) after the
 *    service bind, and verified with a bind as the entry on a new connection; an empty password is
 *    never sent (it would be an anonymous bind).
 *
 * Result code 49 is a wrong password (or, for the service account, rejected credentials); a
 * connection failure is classified as TLS, time-out or unreachable from the diagnostic message and
 * the elapsed time.
 */
class ExtLdapDirectory implements LdapDirectory
{
    private const LDAP_INVALID_CREDENTIALS = 49;

    private const LDAP_TIMELIMIT_EXCEEDED = 3;

    private const LDAP_SERVER_DOWN = -1;

    private const LDAP_TIMEOUT = -5;

    private const LDAP_CONNECT_ERROR = -11;

    public function testBind(LdapConnectionSettings $settings): LdapResult
    {
        $started = microtime(true);
        $link = $this->connect($settings, $started);

        if ($link instanceof LdapResult) {
            return $link;
        }

        try {
            return $this->bind($link, $settings->bindDn, $settings->bindPassword, true, $started) ?? LdapResult::ok();
        } finally {
            @ldap_unbind($link);
        }
    }

    public function findAndBind(LdapConnectionSettings $settings, string $username, #[\SensitiveParameter] string $password, array $attributes): LdapResult
    {
        $started = microtime(true);
        $link = $this->connect($settings, $started);

        if ($link instanceof LdapResult) {
            return $link;
        }

        try {
            $refused = $this->bind($link, $settings->bindDn, $settings->bindPassword, true, $started);

            if ($refused !== null) {
                return $refused;
            }

            $wanted = array_values(array_unique(array_map('strtolower', array_filter($attributes, fn (string $a): bool => $a !== ''))));
            $search = @ldap_search($link, $settings->baseDn, self::userFilter($settings->filter, $settings->usernameField, $username), $wanted, 0, 1);

            if ($search === false) {
                return self::failure($link, true, $started);
            }

            $entries = @ldap_get_entries($link, $search);

            if (! is_array($entries) || (int) ($entries['count'] ?? 0) < 1 || ! isset($entries[0]['dn'])) {
                return LdapResult::unknownUser();
            }

            $dn = (string) $entries[0]['dn'];
            $values = self::attributes($entries[0]);
        } finally {
            @ldap_unbind($link);
        }

        if ($password === '') {
            return LdapResult::wrongPassword($dn);
        }

        $userLink = $this->connect($settings, $started);

        if ($userLink instanceof LdapResult) {
            return $userLink;
        }

        try {
            $refused = $this->bind($userLink, $dn, $password, false, $started);

            return $refused ?? LdapResult::ok($dn, $values);
        } finally {
            @ldap_unbind($userLink);
        }
    }

    /**
     * The connection URIs, separated by spaces.
     *
     * @param  list<string>  $hosts
     */
    public static function uris(array $hosts, int $port, bool $ssl): string
    {
        $port = $ssl && $port === 389 ? 636 : $port;
        $scheme = $ssl ? 'ldaps' : 'ldap';

        return implode(' ', array_map(
            fn (string $host): string => $scheme.'://'.(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '['.$host.']' : $host).':'.$port,
            $hosts,
        ));
    }

    /**
     * The search filter of a user: the username field equal to the escaped username, within the
     * configured filter (LdapRecord's grammar wraps several filters in `(&…)`).
     */
    public static function userFilter(?string $filter, string $field, string $username): string
    {
        $clause = '('.$field.'='.ldap_escape($username, '', LDAP_ESCAPE_FILTER).')';

        return $filter === null || $filter === '' ? $clause : '(&'.$filter.$clause.')';
    }

    /**
     * The options of every connection.
     *
     * @return array<int, int>
     */
    public static function options(bool $ignoreTlsErrors, int $timeout): array
    {
        $options = [
            LDAP_OPT_PROTOCOL_VERSION => 3,
            LDAP_OPT_REFERRALS => 0,
            LDAP_OPT_NETWORK_TIMEOUT => $timeout,
            LDAP_OPT_TIMELIMIT => $timeout,
        ];

        // The certificate is verified unless the configuration ignores TLS errors (FR-056).
        $options[LDAP_OPT_X_TLS_REQUIRE_CERT] = $ignoreTlsErrors ? LDAP_OPT_X_TLS_NEVER : LDAP_OPT_X_TLS_DEMAND;

        // A new TLS context applies the option to this connection, where the extension knows it.
        if ($ignoreTlsErrors && defined('LDAP_OPT_X_TLS_NEWCTX')) {
            $options[(int) constant('LDAP_OPT_X_TLS_NEWCTX')] = 0;
        }

        return $options;
    }

    private function connect(LdapConnectionSettings $settings, float $started): Connection|LdapResult
    {
        // libldap reads the certificate requirement of LDAPS from the global options when the
        // connection is created; it is set for every connection, so no other call inherits it.
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, $settings->ignoreTlsErrors ? LDAP_OPT_X_TLS_NEVER : LDAP_OPT_X_TLS_DEMAND);
        $link = @ldap_connect($settings->uris());

        if (! $link instanceof Connection) {
            return LdapResult::unreachable();
        }

        foreach (self::options($settings->ignoreTlsErrors, self::timeout()) as $option => $value) {
            @ldap_set_option($link, $option, $value);
        }

        if ($settings->useStartTls && ! $settings->useSsl && ! @ldap_start_tls($link)) {
            $result = self::failure($link, true, $started);
            @ldap_unbind($link);

            // A refused StartTLS is a TLS failure unless the server could not be reached at all.
            return in_array($result->status, [LdapStatus::Unreachable, LdapStatus::Unexpected], true) && $result->code !== self::LDAP_SERVER_DOWN
                ? LdapResult::tlsFailure($result->code)
                : $result;
        }

        return $link;
    }

    /**
     * Null when the bind succeeded, else the failure.
     */
    private function bind(Connection $link, string $dn, #[\SensitiveParameter] string $password, bool $service, float $started): ?LdapResult
    {
        if ($password === '') {
            return $service ? LdapResult::credentialsRejected() : LdapResult::wrongPassword($dn);
        }

        return @ldap_bind($link, $dn, $password) ? null : self::failure($link, $service, $started);
    }

    private static function failure(Connection $link, bool $service, float $started): LdapResult
    {
        $code = ldap_errno($link);
        $diagnostic = '';
        @ldap_get_option($link, LDAP_OPT_DIAGNOSTIC_MESSAGE, $diagnostic);
        $diagnostic = strtolower(is_string($diagnostic) ? $diagnostic : '');

        if ($code === self::LDAP_INVALID_CREDENTIALS) {
            return $service ? LdapResult::credentialsRejected() : LdapResult::wrongPassword();
        }

        if ($code === self::LDAP_TIMEOUT || $code === self::LDAP_TIMELIMIT_EXCEEDED) {
            return LdapResult::timedOut();
        }

        if ($code === self::LDAP_SERVER_DOWN || $code === self::LDAP_CONNECT_ERROR) {
            if (preg_match('/certificate|tls|ssl|handshake/', $diagnostic) === 1) {
                return LdapResult::tlsFailure($code);
            }

            if (microtime(true) - $started >= self::timeout() - 0.5) {
                return LdapResult::timedOut();
            }

            return LdapResult::unreachable($code);
        }

        return LdapResult::unexpected($code);
    }

    /**
     * @param  array<int|string, mixed>  $entry
     * @return array<string, list<string>>
     */
    private static function attributes(array $entry): array
    {
        $attributes = [];

        foreach ($entry as $name => $values) {
            if (! is_string($name) || $name === 'dn' || $name === 'count' || ! is_array($values)) {
                continue;
            }

            unset($values['count']);
            $attributes[strtolower($name)] = array_values(array_map('strval', array_filter($values, 'is_scalar')));
        }

        return $attributes;
    }

    private static function timeout(): int
    {
        return max(1, (int) config('restv2.identity_provider.timeout', 7));
    }
}
