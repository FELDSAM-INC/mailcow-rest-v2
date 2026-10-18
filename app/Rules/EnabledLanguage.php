<?php

namespace App\Rules;

use App\Services\Config\MailcowConfig;

/**
 * One of the server's enabled language codes, mailcow's AVAILABLE_LANGUAGES (`notInList`, params
 * `allowed`).
 */
class EnabledLanguage extends CodedRule
{
    public function __construct(private readonly MailcowConfig $config) {}

    protected function check(mixed $value): ?array
    {
        $allowed = array_keys($this->config->availableLanguages());

        return in_array($value, $allowed, true)
            ? null
            : $this->violation('notInList', ['allowed' => $allowed], 'The selected language is not enabled on this server.');
    }
}
