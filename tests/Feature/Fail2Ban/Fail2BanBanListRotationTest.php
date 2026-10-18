<?php

namespace Tests\Feature\Fail2Ban;

use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T081 (US5 AS-9, FR-055): rotating the ban list ID.
 */
class Fail2BanBanListRotationTest extends TestCase
{
    use MonitoringTestHelpers;

    private const OLD = '1b6c4e1e-7b0f-4c9e-9a65-0f6a6c1c8f11';

    public function test_a_new_id_replaces_the_old_one(): void
    {
        MailcowLogs::netfilterDefaults(self::OLD);
        $this->asAdmin();
        $before = (string) $this->getJson('/api/v2/fail2ban/settings')->json('data.etag');

        $response = $this->postEmpty('/api/v2/fail2ban/ban-list-rotation', $this->csrf())->assertOk();

        $id = (string) $response->json('data.banListId');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        $this->assertNotSame(self::OLD, $id);
        $response->assertJsonPath('data.banListUrl', 'https://mail.example.org/f2b-banlist?id='.$id);
        $this->assertNotSame($before, $response->json('data.etag'));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));

        $options = (array) json_decode((string) MailcowLogs::get('F2B_OPTIONS'), true);
        $this->assertSame($id, $options['banlist_id']);
        $this->assertSame(1800, $options['ban_time']);
    }

    public function test_an_outdated_tag_is_refused(): void
    {
        MailcowLogs::netfilterDefaults(self::OLD);
        $this->asAdmin();

        $this->postEmpty('/api/v2/fail2ban/ban-list-rotation', $this->csrf() + ['If-Match' => '"outdated"'])->assertStatus(412);
        $this->assertStringContainsString(self::OLD, (string) MailcowLogs::get('F2B_OPTIONS'));
    }
}
