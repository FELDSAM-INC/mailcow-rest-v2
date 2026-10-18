<?php

namespace Tests\Support\Fakes;

use App\Clients\Dovecot\ActiveSieveScript;
use App\Clients\Dovecot\SieveScriptReader;
use App\Clients\Dovecot\SieveScriptUnavailable;

/**
 * Stands in for the mail server's Sieve scripts (feature 005 FR-053): fixed active scripts per
 * mailbox, or a failure; records the mailboxes asked about.
 */
final class FakeSieveScriptReader implements SieveScriptReader
{
    /** @var array<string, ActiveSieveScript> */
    public array $scripts = [];

    public ?SieveScriptUnavailable $failure = null;

    /** @var list<string> */
    public array $asked = [];

    public function active(string $mailbox): ActiveSieveScript
    {
        $this->asked[] = $mailbox;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->scripts[$mailbox] ?? ActiveSieveScript::none();
    }
}
