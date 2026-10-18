<?php

namespace Tests\Feature\Mailboxes;

use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T067 (FR-036, US5 AS-3): the tags are replaced as a set, merged without regard to
 * letter case, and limited by the tagging limit unless a set over it only shrinks.
 */
class UpdateMailboxTagsTest extends TestCase
{
    use MailboxTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mailcow()->domain('example.org');
        $this->mailcow()->legacyMailbox('anna@example.org', tags: ['Old']);
        $this->asAdmin();
    }

    public function test_the_limit_and_the_case_insensitive_set(): void
    {
        $this->patchMailbox('anna@example.org', ['tags' => array_map(fn (int $i): string => "t{$i}", range(1, 26))])->assertStatus(422)
            ->assertJsonPath('errors.tags.0.code', 'tooMany')
            ->assertJsonPath('errors.tags.0.params.max', 25);

        $this->patchMailbox('anna@example.org', ['tags' => ['Sales', 'sales', 'beta']])->assertOk()->assertJsonPath('data.tags', ['beta', 'Sales']);
        $this->assertSame(['beta', 'Sales'], $this->mailboxTags('anna@example.org'));
        $records = array_values(array_filter($this->auditOf('mailboxUpdate'), fn (object $r): bool => $r->outcome === 'success'));
        $this->assertSame(['old' => ['Old'], 'new' => ['beta', 'Sales']], $records[0]->details['changes']['tags']);
    }

    public function test_the_tagging_limit_setting_applies(): void
    {
        config(['restv2.vars_overrides.TAGGING_LIMIT' => '3']);

        $this->patchMailbox('anna@example.org', ['tags' => ['a', 'b', 'c', 'd']])->assertStatus(422)->assertJsonPath('errors.tags.0.params.max', 3);
        $this->patchMailbox('anna@example.org', ['tags' => ['a', 'b', 'c']])->assertOk();
    }

    public function test_a_legacy_set_over_the_limit_is_shown_in_full_and_may_only_shrink(): void
    {
        $this->mailcow()->legacyMailbox('many@example.org', tags: array_map(fn (int $i): string => sprintf('t%02d', $i), range(1, 30)));

        $this->assertCount(30, (array) $this->getJson('/api/v2/mailboxes/many@example.org')->assertOk()->json('data.tags'));
        $this->patchMailbox('many@example.org', ['tags' => array_map(fn (int $i): string => sprintf('t%02d', $i), range(1, 28))])->assertOk();
        $this->patchMailbox('many@example.org', ['tags' => array_map(fn (int $i): string => sprintf('t%02d', $i), range(1, 31))])->assertStatus(422);
        $this->assertCount(28, $this->mailboxTags('many@example.org'));
    }
}
