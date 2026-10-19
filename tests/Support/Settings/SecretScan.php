<?php

namespace Tests\Support\Settings;

/**
 * Feature 013 SC-012 (research R16): the passphrases the tests send as the Rspamd web interface
 * password. Tests\TestCase checks every response of the suite for them, and SecretScanTest checks
 * the log, the audit log and the stored state.
 */
final class SecretScan
{
    public const PASSPHRASES = ['Moo "cow" pass 1', 'Moo pass 1', 'Moo scan secret 7'];

    /**
     * The passphrase found in the text, raw or JSON-escaped, or null.
     */
    public static function find(string $text): ?string
    {
        foreach (self::PASSPHRASES as $passphrase) {
            foreach ([$passphrase, substr((string) json_encode($passphrase), 1, -1), addslashes($passphrase)] as $form) {
                if (str_contains($text, $form)) {
                    return $passphrase;
                }
            }
        }

        return null;
    }
}
