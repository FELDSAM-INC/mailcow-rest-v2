<?php

namespace App\Rules;

/**
 * An LDAP search filter: at most 1,000 characters, starting with `(`, ending with `)`, with
 * balanced parentheses outside `\` escapes and no text after the outermost closing parenthesis
 * (feature 012 FR-012, format `ldapFilter`).
 */
class LdapFilter extends CodedRule
{
    public const MAX = 1000;

    /**
     * @param  bool  $allowEmpty  an empty string means "none" (the optional settings)
     */
    public function __construct(private readonly bool $allowEmpty = false) {}

    protected function check(mixed $value): ?array
    {
        if (! is_string($value)) {
            return $this->violation('invalidType', ['expected' => 'string']);
        }

        if ($value === '' && $this->allowEmpty) {
            return null;
        }

        if (mb_strlen($value) > self::MAX) {
            return $this->violation('tooLong', ['max' => self::MAX]);
        }

        return self::isBalanced($value) ? null : $this->violation('invalidFormat', ['format' => 'ldapFilter'], 'Must start with "(", end with ")" and have balanced parentheses.');
    }

    public static function isBalanced(string $filter): bool
    {
        if (! str_starts_with($filter, '(') || ! str_ends_with($filter, ')')) {
            return false;
        }

        $depth = 0;
        $length = strlen($filter);

        for ($i = 0; $i < $length; $i++) {
            $char = $filter[$i];

            if ($char === '\\') {
                $i++;

                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;

                if ($depth < 0 || ($depth === 0 && $i !== $length - 1)) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }
}
