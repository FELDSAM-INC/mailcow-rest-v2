<?php

namespace Tests\Feature\Mailboxes;

use Illuminate\Support\Facades\DB;
use Tests\Support\Mailboxes\MailboxTestHelpers;
use Tests\TestCase;

/**
 * Feature 003 T036 (FR-022, FR-023, research R6): the domain's mailbox limit, maximum mailbox quota
 * and domain quota hold for creations, resources and unlimited quotas are not counted, and the
 * domain's default quota applies without a quota and a template.
 */
class CreateMailboxLimitsTest extends TestCase
{
    use MailboxTestHelpers;

    private const PASSWORD = '{PLAIN}secret';

    public function test_a_full_domain_refuses_with_the_count_and_the_limit(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['mailboxes' => 2]);
        $this->mailcow()->legacyMailbox('a@example.org');
        $this->mailcow()->legacyMailbox('b@example.org');
        $this->mailcow()->mailbox('room@example.org', kind: 'location');
        $this->asAdmin();

        $this->createMailbox(['localPart' => 'c', 'domain' => 'example.org', 'password' => self::PASSWORD])
            ->assertStatus(409)
            ->assertJsonPath('type', '/api/v2/problems/mailbox-limit-reached')
            ->assertJsonPath('domain', 'example.org')
            ->assertJsonPath('count', 2)
            ->assertJsonPath('limit', 2);
        $this->assertNull($this->mailboxRow('c@example.org'));
    }

    public function test_resources_are_not_counted(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['mailboxes' => 1]);
        $this->mailcow()->mailbox('room@example.org', kind: 'location');
        $this->asAdmin();

        $this->createMailbox(['localPart' => 'a', 'domain' => 'example.org', 'password' => self::PASSWORD])->assertCreated();
    }

    public function test_the_quota_respects_the_domain_quota_left_and_the_maximum(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['maxquota' => 10240, 'quota' => 15360, 'defquota' => 1024]);
        $this->mailcow()->legacyMailbox('big@example.org', quota: 10240 * self::MIB);
        $this->mailcow()->legacyMailbox('unlimited@example.org', quota: 0);
        $this->asAdmin();

        $this->createMailbox(['localPart' => 'a', 'domain' => 'example.org', 'password' => self::PASSWORD, 'quota' => 8 * self::GIB])
            ->assertStatus(422)
            ->assertJsonPath('errors.quota.0.code', 'outOfRange')
            ->assertJsonPath('errors.quota.0.params', ['min' => 0, 'max' => 5 * self::GIB, 'limit' => 'domainQuotaLeft']);
        $this->createMailbox(['localPart' => 'a', 'domain' => 'example.org', 'password' => self::PASSWORD, 'quota' => 11 * self::GIB])
            ->assertStatus(422)
            ->assertJsonPath('errors.quota.0.params.limit', 'maxMailboxQuota')
            ->assertJsonPath('errors.quota.0.params.max', 10 * self::GIB);
        $this->createMailbox(['localPart' => 'a', 'domain' => 'example.org', 'password' => self::PASSWORD, 'quota' => 5 * self::GIB])->assertCreated();
        $this->createMailbox(['localPart' => 'b', 'domain' => 'example.org', 'password' => self::PASSWORD, 'quota' => 0])->assertCreated()->assertJsonPath('data.quota', 0);
    }

    public function test_without_quota_and_template_the_domain_default_applies(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['defquota' => 2048, 'maxquota' => 10240, 'quota' => 3072]);
        $this->mailcow()->legacyMailbox('big@example.org', quota: 2048 * self::MIB);
        $this->asAdmin();

        // The default is capped at the quota left (1 GiB).
        $this->createMailbox(['localPart' => 'a', 'domain' => 'example.org', 'password' => self::PASSWORD])->assertCreated()->assertJsonPath('data.quota', self::GIB);
    }

    public function test_an_exhausted_domain_quota_is_not_taken_for_unlimited(): void
    {
        $this->mailcow()->domain('example.org', attributes: ['defquota' => 1024, 'maxquota' => 10240, 'quota' => 2048]);
        $this->mailcow()->legacyMailbox('big@example.org', quota: 2048 * self::MIB);
        $this->asAdmin();

        $this->createMailbox(['localPart' => 'a', 'domain' => 'example.org', 'password' => self::PASSWORD])
            ->assertStatus(422)
            ->assertJsonPath('errors.quota.0.params', ['min' => 0, 'max' => 0, 'limit' => 'domainQuotaLeft']);

        DB::table('domain')->where('domain', 'example.org')->update(['defquota' => 0]);
        $this->createMailbox(['localPart' => 'a', 'domain' => 'example.org', 'password' => self::PASSWORD])->assertCreated()->assertJsonPath('data.quota', 0);
    }
}
