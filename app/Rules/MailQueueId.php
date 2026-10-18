<?php

namespace App\Rules;

/**
 * A Postfix queue ID as mailcow uses it, hexadecimal characters only (feature 011 FR-040; legacy
 * dockerapi filters `^[0-9a-fA-F]+$`): otherwise `invalidFormat` with `format: queueId`.
 */
class MailQueueId extends CodedRule
{
    public const PATTERN = '[0-9A-Fa-f]{1,32}';

    protected function check(mixed $value): ?array
    {
        return is_string($value) && preg_match('/^'.self::PATTERN.'$/', $value) === 1
            ? null
            : $this->violation('invalidFormat', ['format' => 'queueId'], 'Hexadecimal characters only (at most 32).');
    }
}
