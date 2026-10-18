<?php

namespace App\Services\Domains;

use App\Rules\WholeMebibytes;
use App\Services\Config\MailcowConfig;
use App\Support\Domain\DomainName;
use App\Support\Http\FieldViolations;

/**
 * The business rules of domains and domain templates (FR-019–FR-021, FR-033, FR-043, FR-047;
 * research D11 step 8), applied to the values after merging the request over the stored or template
 * values. Every violation is collected (docs/conventions.md: every bad entry is named):
 *
 *  - name: legacy syntax (`invalidFormat`, `format: domainName`), not the server host name
 *    (`reserved`);
 *  - limits 0 to 2,147,483,647, quotas 1 MiB to 2^53 bytes (`outOfRange`), whole MiB
 *    (`notMultipleOf`), default ≤ maximum mailbox quota ≤ domain quota (`mustNotExceed`);
 *  - changed limits not below usage (`belowInUse`, with the value in use);
 *  - tags (DomainTagSet); the DKIM selector syntax;
 *  - relay normalisation: "unknown only" implies "all recipients", which implies backup MX.
 */
class DomainRules
{
    public const MAX_INT = 2147483647;

    public const MAX_QUOTA = 9007199254740992;

    public function __construct(private readonly MailcowConfig $config) {}

    /**
     * The normalised ASCII name of a new domain, or null after recording its violation.
     */
    public function name(mixed $raw, FieldViolations $violations): ?string
    {
        $name = is_string($raw) ? DomainName::normalizeValid($raw) : null;

        if ($name === null) {
            $violations->add('name', 'invalidFormat', ['format' => 'domainName']);

            return null;
        }

        $hostname = DomainName::normalize((string) config('restv2.mailcow_hostname'));

        if ($hostname !== null && $hostname === $name) {
            $violations->add('name', 'reserved', [], 'The server\'s own host name cannot be a domain.');

            return null;
        }

        return $name;
    }

    /**
     * Checks and normalises merged values (contract names). Returns the values with normalised
     * tags and relay options.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $currentTags
     * @param  list<string>  $changed  attributes the request changes (for `belowInUse`)
     * @return array<string, mixed>
     */
    public function check(array $values, FieldViolations $violations, array $currentTags = [], ?DomainUsage $usage = null, array $changed = []): array
    {
        foreach (['maxMailboxes', 'maxAliases'] as $field) {
            $value = $values[$field];

            if (! is_int($value) || $value < 0 || $value > self::MAX_INT) {
                $violations->add($field, 'outOfRange', ['min' => 0, 'max' => self::MAX_INT]);
            }
        }

        $quotasValid = true;

        foreach (['defaultMailboxQuota', 'maxMailboxQuota', 'domainQuota'] as $field) {
            $value = $values[$field];

            if (! is_int($value) || $value < WholeMebibytes::MIB || $value > self::MAX_QUOTA) {
                $violations->add($field, 'outOfRange', ['min' => WholeMebibytes::MIB, 'max' => self::MAX_QUOTA]);
                $quotasValid = false;
            } elseif ($value % WholeMebibytes::MIB !== 0) {
                $violations->add($field, 'notMultipleOf', ['factor' => WholeMebibytes::MIB], 'Must be a whole multiple of 1 MiB (1048576 bytes).');
            }
        }

        if ($quotasValid) {
            if ($values['defaultMailboxQuota'] > $values['maxMailboxQuota']) {
                $violations->add('defaultMailboxQuota', 'mustNotExceed', ['field' => 'maxMailboxQuota', 'value' => $values['maxMailboxQuota']]);
            }

            if ($values['maxMailboxQuota'] > $values['domainQuota']) {
                $violations->add('maxMailboxQuota', 'mustNotExceed', ['field' => 'domainQuota', 'value' => $values['domainQuota']]);
            }
        }

        if ($usage !== null) {
            $floors = [
                'maxMailboxes' => $usage->mailboxes,
                'maxAliases' => $usage->aliases,
                'maxMailboxQuota' => $usage->largestMailboxQuota,
                'domainQuota' => $usage->quotaAllocated,
            ];

            foreach ($floors as $field => $inUse) {
                if (in_array($field, $changed, true) && is_int($values[$field]) && $values[$field] < $inUse && ! $violations->has($field)) {
                    $violations->add($field, 'belowInUse', ['inUse' => $inUse]);
                }
            }
        }

        if (mb_strlen((string) ($values['description'] ?? '')) > DomainCapabilitiesService::MAX_DESCRIPTION_LENGTH) {
            $violations->add('description', 'tooLong', ['max' => DomainCapabilitiesService::MAX_DESCRIPTION_LENGTH]);
        }

        $values['tags'] = DomainTagSet::normalize((array) ($values['tags'] ?? []), $currentTags, $this->config->taggingLimit(), $violations);

        if (array_key_exists('dkimSelector', $values) && (! is_string($values['dkimSelector']) || preg_match('/^[A-Za-z0-9._-]{0,255}$/', $values['dkimSelector']) !== 1)) {
            $violations->add('dkimSelector', 'invalidFormat', ['format' => 'dkimSelector']);
        }

        return self::normaliseRelay($values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function normaliseRelay(array $values): array
    {
        if ($values['relayUnknownOnly']) {
            $values['relayAllRecipients'] = true;
        }

        if ($values['relayAllRecipients']) {
            $values['backupMx'] = true;
        }

        return $values;
    }
}
