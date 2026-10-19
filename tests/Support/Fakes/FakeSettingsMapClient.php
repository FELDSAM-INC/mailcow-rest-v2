<?php

namespace Tests\Support\Fakes;

use App\Clients\Rspamd\RspamdUnavailable;
use App\Clients\Rspamd\SettingsMapClient;

/**
 * mailcow's generated Rspamd settings map as a fixed text (feature 013 T007), or unavailable.
 */
final class FakeSettingsMapClient implements SettingsMapClient
{
    public int $fetches = 0;

    private ?string $text = "settings {\n}\n";

    public function returns(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function unavailable(): self
    {
        $this->text = null;

        return $this;
    }

    public function fetch(): string
    {
        $this->fetches++;

        if ($this->text === null) {
            throw new RspamdUnavailable('The settings map endpoint is unavailable.');
        }

        return $this->text;
    }
}
