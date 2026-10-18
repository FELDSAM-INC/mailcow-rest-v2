<?php

namespace App\Services\Mailboxes;

use App\Support\Domain\DomainName;
use App\Support\Http\FieldViolations;

/**
 * A mailbox address normalised as legacy does (feature 003 FR-007, FR-019, research R7): the local
 * part trimmed and lower-cased, the domain lower-cased and converted to ASCII. A valid local part
 * has 1 to 64 ASCII letters, digits and ``! # $ % & ' * + / = ? ^ _ ` { | } ~ - .``, without a
 * leading or trailing dot or two dots in a row; the whole address is a valid e-mail address of at
 * most 255 characters.
 */
final readonly class MailboxAddress
{
    public const LOCAL_PART_PATTERN = "/^[a-z0-9!#$%&'*+\\/=?^_`{|}~-]+(\\.[a-z0-9!#$%&'*+\\/=?^_`{|}~-]+)*$/";

    public const MAX_LOCAL_PART = 64;

    public const MAX_ADDRESS = 255;

    public string $address;

    public string $domainUnicode;

    private function __construct(public string $localPart, public string $domain)
    {
        $this->address = $localPart.'@'.$domain;
        $this->domainUnicode = DomainName::toUnicode($domain);
    }

    /**
     * The address of a path or a bulk target, or null when it cannot be a mailbox address.
     */
    public static function normalize(string $raw): ?self
    {
        $raw = trim($raw);
        $at = strrpos($raw, '@');

        if ($at === false) {
            return null;
        }

        $violations = new FieldViolations;

        return self::fromParts(substr($raw, 0, $at), substr($raw, $at + 1), $violations);
    }

    /**
     * The address of a local part and a domain; null with the violations added under
     * `$localPartField` when the local part or the address is invalid (an invalid domain gives
     * null without a violation: the caller reports it on its own field).
     */
    public static function fromParts(string $localPart, string $domain, FieldViolations $violations, string $localPartField = 'localPart'): ?self
    {
        $local = strtolower(trim($localPart));

        if ($local === '') {
            $violations->add($localPartField, 'required');

            return null;
        }

        if (strlen($local) > self::MAX_LOCAL_PART) {
            $violations->add($localPartField, 'tooLong', ['max' => self::MAX_LOCAL_PART]);

            return null;
        }

        if (preg_match(self::LOCAL_PART_PATTERN, $local) !== 1) {
            $violations->add($localPartField, 'invalidFormat', ['format' => 'localPart'], 'Not a valid local part of an e-mail address.');

            return null;
        }

        $ascii = DomainName::normalizeValid($domain);

        if ($ascii === null) {
            return null;
        }

        $address = new self($local, $ascii);

        if (strlen($address->address) > self::MAX_ADDRESS) {
            $violations->add($localPartField, 'tooLong', ['max' => self::MAX_ADDRESS], 'The address must be at most 255 characters long.');

            return null;
        }

        if (filter_var($address->address, FILTER_VALIDATE_EMAIL) === false) {
            $violations->add($localPartField, 'invalidFormat', ['format' => 'email']);

            return null;
        }

        return $address;
    }
}
