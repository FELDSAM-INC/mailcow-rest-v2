<?php

namespace Tests\Feature\Quarantine\Performance;

use App\Auth\Role;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T082 (SC-008, research R18): a bulk release of 100 items, each delivered and learned
 * as ham with its fuzzy hashes, completes within 60 seconds when the mail server and the spam
 * filter take 100 ms per call (the fake mailer and the fake Rspamd controller delay each call),
 * and reports 100 `done`. Run with `composer test:performance` on an otherwise idle machine.
 */
#[Group('performance')]
class QuarantineBulkReleaseDurationTest extends TestCase
{
    use QuarantineTestHelpers;

    public function test_a_bulk_release_of_100_items_completes_within_60_seconds(): void
    {
        $this->setUpSpamFilter();
        $this->mailcow()->domain('example.org');
        $this->quarantineMailbox('alice@example.org');
        $targets = [];

        for ($i = 0; $i < 100; $i++) {
            $targets[] = ['id' => (string) $this->item('alice@example.org')];
        }

        $this->mailer->delay(100);
        $this->spamFilter->delay(100);
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->signInAs('admin', Role::Admin);

        $start = hrtime(true);
        $this->send('POST', '/api/v2/quarantine/bulk-actions', ['action' => 'release', 'targets' => $targets])
            ->assertOk()
            ->assertJsonPath('data.summary.done', 100);
        $seconds = (hrtime(true) - $start) / 1e9;

        $this->assertLessThan(60.0, $seconds, sprintf('bulk release of 100 items: %.1f s', $seconds));
        $this->mailer->assertDelivered(100);
        $this->spamFilter->assertCalled('/learnham', 100);
        $this->spamFilter->assertCalled('/fuzzyadd', 100);
    }
}
