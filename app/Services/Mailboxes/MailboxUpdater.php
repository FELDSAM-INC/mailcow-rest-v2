<?php

namespace App\Services\Mailboxes;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\Memcached\MemcachedFlusher;
use App\Services\Audit\AuditLogger;
use App\Services\Config\MailcowConfig;
use App\Services\Groupware\SogoDirectory;
use App\Services\Passwords\HashedPassword;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Tags\TagSet;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Mailbox changes, legacy mailbox('edit', 'mailbox'), acl('edit', 'user'),
 * mailbox('edit', 'mailbox_custom_attribute') and the tag actions, with the spec's deviations
 * (feature 003 FR-029–FR-037, research R13). In one transaction on the locked row (and the locked
 * domain row when the quota changes):
 *
 *  1. the version check (`412`);
 *  2. the domain administrator's permission flags against the current values (`403`);
 *  3. the business rules on the merged values (`422`, every violation; a plain password needs the
 *     password policy from mailcow's shared store, `503` when it is down);
 *  4. the writes: the own alias's status, the `mailbox` row (the authentication source before the
 *     password, so that a password sent with a switch to `mailcow` applies; legacy's `JSON_SET` of
 *     the changed attributes and `attribute_hash = ''`), the tags, the changed `user_acl` columns,
 *     the custom attributes, the groupware entry and the audit record with each change.
 *
 * A patch equal to the stored values writes nothing and records nothing. After the commit, the
 * groupware cache is flushed unless the bulk caller defers it.
 */
class MailboxUpdater
{
    public function __construct(
        private readonly MailboxValidator $validator,
        private readonly MailboxPermissionGuard $guard,
        private readonly MailboxPasswordHasher $hasher,
        private readonly MailboxVersion $version,
        private readonly SogoDirectory $sogo,
        private readonly MemcachedFlusher $flusher,
        private readonly MailcowConfig $config,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $patch  the sent members of `MailboxPatch`
     * @param  string|null  $ifMatch  the `If-Match` value (or a bulk item's version as one)
     * @return bool whether anything changed
     */
    public function update(Principal $principal, string $address, array $patch, ?string $ifMatch, ?string $bulkRequestId = null, bool $deferFlush = false): bool
    {
        $changed = DB::transaction(function () use ($principal, $address, $patch, $ifMatch, $bulkRequestId): bool {
            $domainName = DB::table('mailbox')->where('username', $address)->value('domain');
            $domain = array_key_exists('quota', $patch) && is_string($domainName)
                ? DB::table('domain')->where('domain', $domainName)->lockForUpdate()->first()
                : null;
            $row = DB::table('mailbox')->where('username', $address)->where('kind', '')->lockForUpdate()->first();

            if (! $row instanceof stdClass) {
                throw new NotFoundHttpException('The mailbox does not exist.');
            }

            $acl = DB::table('user_acl')->where('username', $address)->lockForUpdate()->first();
            $tags = DB::table('tags_mailbox')->where('username', $address)->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all();

            if (ETag::preconditionFails($ifMatch, $this->version->forMailbox($row, $acl, $tags))) {
                throw new PreconditionFailedHttpException('The mailbox was changed since it was read.');
            }

            $current = self::currentValues($row, $acl, $tags);
            $this->guard->check($principal, $patch, $current, false);

            [$values, $password] = $this->merged($row, $current, $patch, $domain);
            $changes = self::changes($current, $values);

            if ($password !== null) {
                $changes['password'] = ['passwordSet' => true, 'preHashed' => $password->preHashed];
            }

            if ($changes === []) {
                return false;
            }

            $this->write($row, $current, $values, $password);

            $details = ['changes' => $changes];

            if ($bulkRequestId !== null) {
                $details['bulkRequestId'] = $bulkRequestId;
            }

            $this->audit->record(AuditEventType::MailboxUpdate, true, $principal, ['type' => 'mailbox', 'id' => $address, 'name' => (string) $values['displayName']], null, null, $details);

            return true;
        }, 3);

        if ($changed && ! $deferFlush && ! config('restv2.skip_sogo')) {
            $this->flusher->flush();
        }

        return $changed;
    }

    /**
     * The changeable values of a stored mailbox, in contract names.
     *
     * @param  list<string>  $tags
     * @return array<string, mixed>
     */
    public static function currentValues(object $row, ?object $acl, array $tags): array
    {
        $attributes = MailboxReader::decode($row->attributes ?? null);
        $flag = fn (string $key, bool $default): bool => MailboxValues::flag($attributes[$key] ?? null) ?? $default;
        $protocols = [];

        foreach (MailboxValues::PROTOCOLS as $protocol => $key) {
            $protocols[$protocol] = $flag($key, true);
        }

        $recovery = $attributes['recovery_email'] ?? null;

        return [
            'displayName' => (string) ($row->name ?? ''),
            'status' => MailboxValues::status($row->active),
            'quota' => (int) $row->quota,
            'forcePasswordChange' => $flag('force_pw_update', false),
            'forceSecondFactorSetup' => $flag('force_tfa', false),
            'webmailRedirect' => $flag('sogo_access', true),
            'protocolAccess' => $protocols,
            'authSource' => MailboxValues::authSource($row->authsource ?? null),
            'recoveryEmail' => is_string($recovery) && $recovery !== '' ? $recovery : null,
            'tags' => TagSet::sort($tags),
            'userPermissions' => UserPermissions::fromRow($acl),
            'customAttributes' => array_map('strval', array_filter(MailboxReader::decode($row->custom_attributes ?? null), 'is_scalar')),
        ];
    }

    /**
     * The values after the patch, checked by every business rule, and the new password.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $patch
     * @return array{array<string, mixed>, HashedPassword|null}
     */
    private function merged(object $row, array $current, array $patch, ?object $domain): array
    {
        $violations = new FieldViolations;
        $values = MailboxDefaults::merge($current, array_intersect_key($patch, array_flip([
            'status', 'quota', 'forcePasswordChange', 'forceSecondFactorSetup', 'webmailRedirect', 'protocolAccess', 'userPermissions', 'authSource',
        ])));

        if (array_key_exists('displayName', $patch)) {
            $values['displayName'] = MailboxValidator::displayName((string) $patch['displayName'], (string) $row->local_part);
        }

        $source = (string) $values['authSource'];
        $this->validator->authSource($source, $current['authSource'], $violations);
        $external = MailboxValues::isExternal($source);

        if ($external && $source !== $current['authSource']) {
            // Legacy turns these off when a mailbox is switched to an external source.
            $values['forcePasswordChange'] = array_key_exists('forcePasswordChange', $patch) ? $values['forcePasswordChange'] : false;

            if ($source === 'genericOidc') {
                $values['forceSecondFactorSetup'] = array_key_exists('forceSecondFactorSetup', $patch) ? $values['forceSecondFactorSetup'] : false;
            }
        }

        if ($external && ($patch['forcePasswordChange'] ?? false) === true) {
            $violations->add('forcePasswordChange', 'conflictsWith', ['field' => 'authSource', 'value' => $source], 'An externally authenticated mailbox cannot be forced to change its password here.');
        }

        if ($source === 'genericOidc' && ($patch['forceSecondFactorSetup'] ?? false) === true) {
            $violations->add('forceSecondFactorSetup', 'conflictsWith', ['field' => 'authSource', 'value' => $source], 'A generic OIDC mailbox cannot be forced to set up a second factor here.');
        }

        $password = null;

        if (array_key_exists('password', $patch)) {
            if ($external) {
                $violations->add('password', 'conflictsWith', ['field' => 'authSource', 'value' => $source], 'An externally authenticated mailbox has no password here.');
            } else {
                $this->validator->passwordPolicy((string) $patch['password'], $violations);
            }
        } elseif (! $external && MailboxValues::isExternal((string) $current['authSource']) && (string) $row->password === '') {
            $violations->add('password', 'required', [], 'A password is needed: the mailbox has none stored.');
        }

        if (array_key_exists('recoveryEmail', $patch)) {
            $email = trim((string) ($patch['recoveryEmail'] ?? ''));
            $values['recoveryEmail'] = $email === '' ? null : $email;

            if ($email !== '' && $external) {
                $violations->add('recoveryEmail', 'conflictsWith', ['field' => 'authSource', 'value' => $source], 'Only mailboxes authenticated by mailcow have a recovery e-mail.');
            } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $violations->add('recoveryEmail', 'invalidFormat', ['format' => 'email']);
            }
        }

        if (array_key_exists('tags', $patch)) {
            $values['tags'] = TagSet::sort(TagSet::normalise((array) $patch['tags'], $current['tags'], $this->config->taggingLimit(), $violations));
        }

        if (array_key_exists('quota', $patch) && $this->validator->wholeMebibytes($patch['quota'], $violations) && $patch['quota'] !== $current['quota'] && $domain !== null) {
            $others = (int) DB::table('mailbox')->where('domain', $domain->domain)->where('kind', '')->where('username', '!=', $row->username)->sum('quota');
            $largest = max(0, min((int) $domain->maxquota * MailboxValidator::MIB, (int) $domain->quota * MailboxValidator::MIB - $others));
            $this->validator->changedQuota((int) $patch['quota'], $largest, $violations);
        }

        if (array_key_exists('customAttributes', $patch) && is_array($patch['customAttributes'])) {
            $values['customAttributes'] = $this->validator->customAttributes($current['customAttributes'], $patch['customAttributes'], $violations);
        }

        $violations->throwIfAny();

        return [$values, array_key_exists('password', $patch) ? $this->hasher->hash((string) $patch['password']) : null];
    }

    /**
     * Each changed value as `{old, new}`, nested members as dot paths.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $values
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private static function changes(array $current, array $values): array
    {
        $changes = [];

        foreach ($current as $member => $old) {
            $new = $values[$member];

            if (in_array($member, ['protocolAccess', 'userPermissions', 'customAttributes'], true)) {
                $keys = array_unique([...array_keys($old), ...array_keys($new)]);
                sort($keys);

                foreach ($keys as $key) {
                    if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                        $changes[$member.'.'.$key] = ['old' => $old[$key] ?? null, 'new' => $new[$key] ?? null];
                    }
                }
            } elseif ($member === 'tags' ? $old !== TagSet::sort($new) : $old !== $new) {
                $changes[$member] = ['old' => $old, 'new' => $member === 'tags' ? TagSet::sort($new) : $new];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $values
     */
    private function write(object $row, array $current, array $values, ?HashedPassword $password): void
    {
        $address = (string) $row->username;
        $set = [];

        foreach (['forcePasswordChange' => 'force_pw_update', 'forceSecondFactorSetup' => 'force_tfa', 'webmailRedirect' => 'sogo_access'] as $member => $key) {
            if ($values[$member] !== $current[$member]) {
                $set[$key] = $values[$member] ? '1' : '0';
            }
        }

        foreach (MailboxValues::PROTOCOLS as $protocol => $key) {
            if ($values['protocolAccess'][$protocol] !== $current['protocolAccess'][$protocol]) {
                $set[$key] = $values['protocolAccess'][$protocol] ? '1' : '0';
            }
        }

        if ($values['recoveryEmail'] !== $current['recoveryEmail']) {
            $set['recovery_email'] = (string) $values['recoveryEmail'];
        }

        // Legacy clears the identity-provider template marker on every edit (FR-034).
        $set['attribute_hash'] = '';
        $paths = [];
        $bindings = [];

        foreach ($set as $key => $value) {
            $paths[] = "'$.{$key}', ?";
            $bindings[] = $value;
        }

        $columns = [
            'active' => MailboxValues::STATUSES[$values['status']],
            'name' => (string) $values['displayName'],
            'quota' => (int) $values['quota'],
            'authsource' => MailboxValues::AUTH_SOURCES[$values['authSource']],
        ];

        if ($values['customAttributes'] !== $current['customAttributes']) {
            $columns['custom_attributes'] = json_encode((object) $values['customAttributes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if ($password !== null) {
            $columns['password'] = $password->value;
            $paths[] = "'$.passwd_update', NOW()";
        }

        $assignments = implode(', ', array_map(fn (string $column): string => "`{$column}` = ?", array_keys($columns)));
        DB::update(
            "UPDATE `mailbox` SET {$assignments}, `attributes` = JSON_SET(`attributes`, ".implode(', ', $paths).') WHERE `username` = ?',
            [...array_values($columns), ...$bindings, $address],
        );

        if ($values['status'] !== $current['status']) {
            DB::table('alias')->where('address', $address)->update(['active' => MailboxValues::STATUSES[$values['status']]]);
        }

        if ($values['tags'] !== $current['tags']) {
            DB::table('tags_mailbox')->where('username', $address)->delete();

            foreach ($values['tags'] as $tag) {
                DB::table('tags_mailbox')->insert(['username' => $address, 'tag_name' => $tag]);
            }
        }

        $acl = [];

        foreach (UserPermissions::COLUMNS as $permission => $column) {
            if ($values['userPermissions'][$permission] !== $current['userPermissions'][$permission]) {
                $acl[$column] = (int) $values['userPermissions'][$permission];
            }
        }

        if ($acl !== []) {
            DB::table('user_acl')->updateOrInsert(['username' => $address], $acl);
        }

        $this->sogo->refresh([$address]);
    }
}
