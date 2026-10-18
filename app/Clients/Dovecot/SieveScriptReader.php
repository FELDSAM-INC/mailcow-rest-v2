<?php

namespace App\Clients\Dovecot;

/**
 * Reads the active Sieve script of a mailbox from the mail server (feature 005 FR-053, research
 * R13). Faked in tests.
 */
interface SieveScriptReader
{
    /**
     * @throws SieveScriptUnavailable when the mail server cannot be reached or does not answer in time
     */
    public function active(string $mailbox): ActiveSieveScript;
}
