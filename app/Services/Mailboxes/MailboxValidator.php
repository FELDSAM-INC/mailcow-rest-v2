<?php

namespace App\Services\Mailboxes;

use App\Auth\Principal;
use App\Auth\Role;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Rules\CustomAttributeName;
use App\Rules\WholeMebibytes;
use App\Services\Passwords\MailboxPasswordHasher;
use App\Services\Passwords\PasswordPolicyChecker;
use App\Services\Passwords\PasswordPolicyReader;
use App\Support\Domain\DomainName;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Business rules of mailbox creations and changes (feature 003 data-model.md "Validation"), each
 * adding its violations to one FieldViolations so that every bad member is reported together:
 * the creation domain, the authentication source, the password policy, whole-MiB quotas and the
 * domain's quota limits, and custom attributes. Address syntax is MailboxAddress's and tags are
 * TagSet's.
 */
class MailboxValidator
{
    public const MIB = 1048576;

    public const CUSTOM_ATTRIBUTE_VALUE_MAX = 4096;

    public function __construct(
        private readonly MailboxScope $scope,
        private readonly PasswordPolicyReader $policy,
        private readonly PasswordPolicyChecker $checker,
    ) {}

    /**
     * The `domain` row of a creation (FR-020): a primary domain in the caller's scope. An alias
     * domain in scope gives `notEligible` (`kind: aliasDomain`); anything else not found or out of
     * scope gives `notFound`.
     */
    public function creationDomain(Principal $principal, string $raw, FieldViolations $violations): ?stdClass
    {
        $name = DomainName::normalizeValid($raw);

        if ($name === null) {
            $violations->add('domain', 'invalidFormat', ['format' => 'domainName']);

            return null;
        }

        if ($this->scope->domainInScope($principal, $name)) {
            $row = DB::table('domain')->where('domain', $name)->first();

            if ($row instanceof stdClass) {
                return $row;
            }
        }

        $target = DB::table('alias_domain')->where('alias_domain', $name)->value('target_domain');

        if (is_string($target) && ($principal->role === Role::Admin || $this->scope->domainInScope($principal, $target))) {
            $violations->add('domain', 'notEligible', ['kind' => 'aliasDomain'], 'Mailboxes live in primary domains, not in alias domains.');
        } else {
            $violations->add('domain', 'notFound', [], 'The domain does not exist.');
        }

        return null;
    }

    /**
     * An authentication source that is not `mailcow` or the configured provider type
     * (`notInList`); an unchanged source is always accepted.
     */
    public function authSource(string $source, ?string $current, FieldViolations $violations): void
    {
        $allowed = IdentityProviderType::allowedSources();

        if ($source !== $current && ! in_array($source, $allowed, true)) {
            $violations->add('authSource', 'notInList', ['allowed' => $allowed]);
        }
    }

    /**
     * The policy check of a plain password (FR-024); pre-hashed values are not checked.
     *
     * @throws SharedStoreUnavailable when the policy cannot be read
     */
    public function passwordPolicy(string $password, FieldViolations $violations): void
    {
        if (MailboxPasswordHasher::isPreHashed($password)) {
            return;
        }

        foreach ($this->checker->violations($password, $this->policy->effective()) as $violation) {
            $violations->add('password', $violation['code'], $violation['params']);
        }
    }

    /**
     * Whether a quota is a whole number of MiB (`notMultipleOf`).
     */
    public function wholeMebibytes(mixed $quota, FieldViolations $violations, string $field = 'quota'): bool
    {
        if (is_int($quota) && $quota % WholeMebibytes::MIB === 0) {
            return true;
        }

        $violations->add($field, 'notMultipleOf', ['factor' => WholeMebibytes::MIB]);

        return false;
    }

    /**
     * The quota limits of a new mailbox (FR-023): at most the domain's maximum mailbox quota and
     * the domain quota left; an unlimited quota is not counted.
     *
     * @param  int  $allocated  bytes of the domain's mailboxes' quotas
     */
    public function creationQuota(int $quota, object $domain, int $allocated, FieldViolations $violations): void
    {
        if ($quota === 0) {
            return;
        }

        $max = (int) $domain->maxquota * self::MIB;
        $left = max(0, (int) $domain->quota * self::MIB - $allocated);

        if ($quota > $max) {
            $violations->add('quota', 'outOfRange', ['min' => 0, 'max' => $max, 'limit' => 'maxMailboxQuota'], 'The quota exceeds the domain\'s maximum mailbox quota.');
        } elseif ($quota > $left) {
            $violations->add('quota', 'outOfRange', ['min' => 0, 'max' => $left, 'limit' => 'domainQuotaLeft'], 'The quota exceeds the domain quota left.');
        }
    }

    /**
     * A changed quota (FR-030): at most the largest quota the mailbox may be given; unlimited is
     * not limited here (it needs the `unlimitedQuota` flag).
     */
    public function changedQuota(int $quota, int $largestAllowed, FieldViolations $violations): void
    {
        if ($quota !== 0 && $quota > $largestAllowed) {
            $violations->add('quota', 'outOfRange', ['min' => 0, 'max' => $largestAllowed, 'limit' => 'largestAllowedQuota'], 'The quota exceeds the largest quota allowed for this mailbox.');
        }
    }

    /**
     * Custom attributes merged with a patch (FR-037): names of 1 to 255 characters without
     * whitespace, text values of at most 4,096 characters or null (removes the attribute), and at
     * most the server's limit after merging, unless a set already over the limit does not grow.
     *
     * @param  array<string, string>  $current
     * @param  array<mixed>  $patch
     * @return array<string, string>
     */
    public function customAttributes(array $current, array $patch, FieldViolations $violations): array
    {
        $merged = $current;

        foreach ($patch as $name => $value) {
            $name = (string) $name;
            $field = 'customAttributes.'.$name;

            if (preg_match(CustomAttributeName::PATTERN, $name) !== 1) {
                $violations->add($field, 'invalidFormat', ['format' => 'attributeName'], 'Must be 1 to 255 characters without whitespace.');

                continue;
            }

            if ($value === null) {
                unset($merged[$name]);
            } elseif (! is_string($value)) {
                $violations->add($field, 'invalidType', ['expected' => 'string']);
            } elseif (mb_strlen($value) > self::CUSTOM_ATTRIBUTE_VALUE_MAX) {
                $violations->add($field, 'tooLong', ['max' => self::CUSTOM_ATTRIBUTE_VALUE_MAX]);
            } else {
                $merged[$name] = $value;
            }
        }

        $limit = (int) config('restv2.mailbox_custom_attribute_limit', 100);

        if (count($merged) > $limit && count($merged) > count($current)) {
            $violations->add('customAttributes', 'tooMany', ['max' => $limit]);
        }

        return $merged;
    }

    /**
     * Legacy's display name rule: leading `<` and trailing `>` removed; empty means the local
     * part (FR-022).
     */
    public static function displayName(?string $raw, string $localPart): string
    {
        $name = ltrim(rtrim(trim((string) $raw), '>'), '<');

        return trim($name) === '' ? $localPart : $name;
    }
}
