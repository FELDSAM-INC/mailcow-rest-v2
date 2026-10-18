<?php

namespace App\Services\Mailboxes;

use App\Services\Config\MailcowConfig;
use App\Services\RateLimits\RateLimitStore;

/**
 * Starting values of new mailboxes (feature 003 FR-022, FR-050, research R5), in contract names:
 *
 *  - the built-in defaults: legacy's `$MAILBOX_DEFAULT_ATTRIBUTES` (an API server setting with the
 *    upstream values, MailcowConfig), status active, no rate limit, no tags, the `user_acl` column
 *    defaults and, for templates, an unlimited quota;
 *  - the codec of mailbox templates (`templates.attributes` with legacy's keys), tolerant of keys a
 *    stored template lacks and of integer, string or boolean flags;
 *  - the chain request → named template → Default template → built-in defaults, member by member
 *    for protocol access and user permissions.
 */
class MailboxDefaults
{
    public function __construct(private readonly MailcowConfig $config) {}

    /**
     * Every value a mailbox or template starts with when nothing else gives one.
     *
     * @return array<string, mixed>
     */
    public function builtIn(): array
    {
        $attributes = $this->config->mailboxDefaultAttributes();
        $flag = fn (string $key, bool $default): bool => MailboxValues::flag($attributes[$key] ?? null) ?? $default;
        $protocols = [];

        foreach (MailboxValues::PROTOCOLS as $protocol => $key) {
            $protocols[$protocol] = $flag($key, true);
        }

        $tagged = (string) ($attributes['tagged_mail_handler'] ?? 'none');
        $notification = (string) ($attributes['quarantine_notification'] ?? 'hourly');

        return [
            'quota' => 0,
            'status' => 'active',
            'forcePasswordChange' => $flag('force_pw_update', false),
            'forceSecondFactorSetup' => $flag('force_tfa', false),
            'webmailRedirect' => $flag('sogo_access', true),
            'protocolAccess' => $protocols,
            'userPermissions' => UserPermissions::DEFAULTS,
            'tags' => [],
            'taggedMailHandling' => in_array($tagged, MailboxValues::TAGGED_MAIL_HANDLING, true) ? $tagged : 'none',
            'quarantineNotification' => in_array($notification, MailboxValues::QUARANTINE_NOTIFICATIONS, true) ? $notification : 'hourly',
            'quarantineCategory' => MailboxValues::quarantineCategory($attributes['quarantine_category'] ?? 'reject') ?? 'rejected',
            'tlsEnforceInbound' => $flag('tls_enforce_in', false),
            'tlsEnforceOutbound' => $flag('tls_enforce_out', false),
            'rateLimit' => null,
        ];
    }

    /** The storage format of new mailboxes (legacy `mailbox_format`, `maildir:`). */
    public function mailboxFormat(): string
    {
        $format = $this->config->mailboxDefaultAttributes()['mailbox_format'] ?? 'maildir:';

        return is_string($format) && $format !== '' ? $format : 'maildir:';
    }

    /**
     * The values a stored template holds, in contract names; keys it lacks or holds invalid values
     * for are absent.
     *
     * @return array<string, mixed>
     */
    public function decodeTemplate(mixed $json): array
    {
        $stored = is_string($json) ? json_decode($json, true) : null;
        $values = [];

        if (! is_array($stored)) {
            return $values;
        }

        if (is_numeric($stored['quota'] ?? null)) {
            $values['quota'] = max(0, (int) $stored['quota']);
        }

        if (is_array($stored['tags'] ?? null)) {
            $values['tags'] = array_values(array_map('strval', array_filter($stored['tags'], fn (mixed $t): bool => is_scalar($t) && trim((string) $t) !== '')));
        }

        if (array_key_exists('active', $stored) && is_numeric($stored['active'])) {
            $values['status'] = MailboxValues::status($stored['active']);
        }

        foreach (['force_pw_update' => 'forcePasswordChange', 'force_tfa' => 'forceSecondFactorSetup', 'sogo_access' => 'webmailRedirect', 'tls_enforce_in' => 'tlsEnforceInbound', 'tls_enforce_out' => 'tlsEnforceOutbound'] as $key => $name) {
            $flag = MailboxValues::flag($stored[$key] ?? null);

            if ($flag !== null) {
                $values[$name] = $flag;
            }
        }

        foreach (MailboxValues::PROTOCOLS as $protocol => $key) {
            $flag = MailboxValues::flag($stored[$key] ?? null);

            if ($flag !== null) {
                $values['protocolAccess'][$protocol] = $flag;
            }
        }

        foreach (UserPermissions::COLUMNS as $permission => $column) {
            $flag = MailboxValues::flag($stored['acl_'.$column] ?? null);

            if ($flag !== null) {
                $values['userPermissions'][$permission] = $flag;
            }
        }

        if (in_array($stored['tagged_mail_handler'] ?? null, MailboxValues::TAGGED_MAIL_HANDLING, true)) {
            $values['taggedMailHandling'] = $stored['tagged_mail_handler'];
        }

        if (in_array($stored['quarantine_notification'] ?? null, MailboxValues::QUARANTINE_NOTIFICATIONS, true)) {
            $values['quarantineNotification'] = $stored['quarantine_notification'];
        }

        $category = MailboxValues::quarantineCategory($stored['quarantine_category'] ?? null);

        if ($category !== null) {
            $values['quarantineCategory'] = $category;
        }

        if (array_key_exists('rl_value', $stored)) {
            $values['rateLimit'] = MailboxValues::rateLimitFromTemplate($stored['rl_value'], $stored['rl_frame'] ?? 's');
        }

        return $values;
    }

    /**
     * A template's values with the built-in defaults for what it lacks (feature 003 FR-050).
     *
     * @return array<string, mixed>
     */
    public function templateValues(mixed $json): array
    {
        return self::merge($this->builtIn(), $this->decodeTemplate($json));
    }

    /**
     * Legacy's template attributes of complete values: every key, in legacy's order, with integer
     * flags, the quota in bytes and `rl_value: ""` / `rl_frame: "s"` for no rate limit.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function encodeTemplate(array $values): array
    {
        $rateLimit = $values['rateLimit'] ?? null;
        $attributes = [
            'quota' => (int) $values['quota'],
            'tags' => array_values($values['tags']),
            'tagged_mail_handler' => (string) $values['taggedMailHandling'],
            'quarantine_notification' => (string) $values['quarantineNotification'],
            'quarantine_category' => MailboxValues::QUARANTINE_CATEGORIES[$values['quarantineCategory']] ?? 'reject',
            'rl_frame' => is_array($rateLimit) ? (RateLimitStore::FRAMES[$rateLimit['frame']] ?? 's') : 's',
            'rl_value' => is_array($rateLimit) ? (int) $rateLimit['value'] : '',
            'force_pw_update' => (int) $values['forcePasswordChange'],
            'force_tfa' => (int) $values['forceSecondFactorSetup'],
            'sogo_access' => (int) $values['webmailRedirect'],
            'active' => MailboxValues::STATUSES[$values['status']] ?? 1,
            'tls_enforce_in' => (int) $values['tlsEnforceInbound'],
            'tls_enforce_out' => (int) $values['tlsEnforceOutbound'],
        ];

        foreach (MailboxValues::PROTOCOLS as $protocol => $key) {
            $attributes[$key] = (int) $values['protocolAccess'][$protocol];
        }

        foreach (UserPermissions::COLUMNS as $permission => $column) {
            $attributes['acl_'.$column] = (int) $values['userPermissions'][$permission];
        }

        return $attributes;
    }

    /**
     * Creation values (FR-022): each value from the request, else the named template, else the
     * Default template, else the built-in defaults. The quota is the request's or the named
     * template's; null means the domain's default quota for a new mailbox applies. The baseline is
     * what the mailbox would get without the request (MailboxPermissionGuard compares with it).
     *
     * @param  array<string, mixed>  $request  the request's value members (contract names)
     * @param  array<string, mixed>|null  $named  the named template's decoded values
     * @param  array<string, mixed>|null  $default  the Default template's decoded values
     */
    public function resolve(array $request, ?array $named, ?array $default): ResolvedMailboxValues
    {
        $baseline = self::merge(self::merge($this->builtIn(), $default ?? []), $named ?? []);
        $baseline['quota'] = $named !== null && array_key_exists('quota', $named) ? $named['quota'] : null;

        return new ResolvedMailboxValues(self::merge($baseline, $request), $baseline);
    }

    /**
     * Values overridden by others: nested protocol access and user permissions member by member,
     * everything else as a whole.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            $base[$key] = in_array($key, ['protocolAccess', 'userPermissions'], true) && is_array($value)
                ? array_merge((array) ($base[$key] ?? []), $value)
                : $value;
        }

        return $base;
    }
}
