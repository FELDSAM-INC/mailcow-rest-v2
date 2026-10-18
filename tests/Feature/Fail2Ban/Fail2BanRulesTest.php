<?php

namespace Tests\Feature\Fail2Ban;

use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\Support\Redis\MailcowLogs;
use Tests\TestCase;

/**
 * Feature 011 T077 (US5 AS-7, FR-049, FR-050, research R20): reading and replacing the ban rules.
 */
class Fail2BanRulesTest extends TestCase
{
    use MonitoringTestHelpers;

    public function test_the_rules_are_numbered_from_one(): void
    {
        MailcowLogs::f2bRegex(MailcowLogs::defaultRules());
        $this->asAdmin();

        $response = $this->getJson('/api/v2/fail2ban/rules')->assertOk();

        $response->assertJsonPath('data.stored', true)->assertJsonPath('data.banServiceRunning', true);
        $this->assertCount(9, $response->json('data.rules'));
        $this->assertSame(['number' => 1, 'pattern' => MailcowLogs::defaultRules()[0]], $response->json('data.rules.0'));
        $response->assertHeader('ETag', (string) $response->json('data.etag'));
    }

    public function test_nothing_stored(): void
    {
        $this->asAdmin();

        $this->getJson('/api/v2/fail2ban/rules')->assertOk()->assertJsonPath('data.rules', [])->assertJsonPath('data.stored', false);
    }

    public function test_the_rules_are_replaced_as_legacy_stores_them(): void
    {
        MailcowLogs::f2bRegex(MailcowLogs::defaultRules());
        $this->asAdmin();

        $this->putJson('/api/v2/fail2ban/rules', ['rules' => ['Login failed for .+ from ([0-9a-f\.:]+)', 'GET /admin/.* from ([0-9a-f\.:]+)']], $this->csrf())
            ->assertOk()
            ->assertJsonPath('data.rules.1', ['number' => 2, 'pattern' => 'GET /admin/.* from ([0-9a-f\.:]+)']);

        $this->assertSame('{"1":"Login failed for .+ from ([0-9a-f\\\\.:]+)","2":"GET /admin/.* from ([0-9a-f\\\\.:]+)"}', MailcowLogs::get('F2B_REGEX'));
    }

    public function test_an_invalid_rule_set_changes_nothing(): void
    {
        MailcowLogs::f2bRegex(['keep (\d+)']);
        $this->asAdmin();

        $errors = (array) $this->putJson('/api/v2/fail2ban/rules', ['rules' => ['ok ([0-9.]+)', 'no group here', '[broken', '(?<ip>x)']], $this->csrf())->assertStatus(422)->json('errors');

        $this->assertSame('missingCaptureGroup', $errors['rules.1'][0]['code']);
        $this->assertSame('invalidRegex', $errors['rules.2'][0]['code']);
        $this->assertNotSame('', $errors['rules.2'][0]['params']['error']);
        $this->assertSame('invalidRegex', $errors['rules.3'][0]['code']);
        $this->assertArrayNotHasKey('rules.0', $errors);
        $this->assertSame('{"1": "keep (\\\\d+)"}', MailcowLogs::get('F2B_REGEX'));
    }

    public function test_invalid_list_sizes_are_refused(): void
    {
        $this->withoutRequestValidation();
        $this->asAdmin();

        $this->putJson('/api/v2/fail2ban/rules', ['rules' => []], $this->csrf())->assertStatus(422)->assertJsonPath('errors.rules.0.code', 'required');
        $this->putJson('/api/v2/fail2ban/rules', ['rules' => array_fill(0, 101, 'a (b)')], $this->csrf())->assertStatus(422)->assertJsonPath('errors.rules.0.code', 'tooMany');
        $errors = (array) $this->putJson('/api/v2/fail2ban/rules', ['rules' => [str_repeat('a', 996).'(b)xx']], $this->csrf())->assertStatus(422)->json('errors');
        $this->assertSame('tooLong', $errors['rules.0'][0]['code']);
        $this->assertNull(MailcowLogs::get('F2B_REGEX'));
    }

    public function test_an_outdated_tag_is_refused(): void
    {
        MailcowLogs::f2bRegex(['keep (\d+)']);
        $this->asAdmin();
        $tag = (string) $this->getJson('/api/v2/fail2ban/rules')->json('data.etag');
        MailcowLogs::f2bRegex(['changed (\d+)']);

        $this->putJson('/api/v2/fail2ban/rules', ['rules' => ['new (\d+)']], $this->csrf() + ['If-Match' => $tag])->assertStatus(412);
        $this->assertSame('{"1": "changed (\\\\d+)"}', MailcowLogs::get('F2B_REGEX'));
    }
}
