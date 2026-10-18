<?php

namespace Tests\Feature\Logs;

use Illuminate\Support\Facades\DB;
use Tests\Support\Monitoring\LegacyUiLogRows;
use Tests\Support\Monitoring\MonitoringTestHelpers;
use Tests\TestCase;

/**
 * Feature 011 T049 (FR-022, FR-029, research R13): the legacy UI activity log (table `logs`).
 */
class LegacyUiActivityLogTest extends TestCase
{
    use LegacyUiLogRows;
    use MonitoringTestHelpers;

    public function test_entries_carry_the_message_key_parameters_and_call(): void
    {
        $id = $this->legacyUiLog('success', ['mailbox_modified', 'alice@example.org'], ['mailbox', 'edit', ['username' => 'alice@example.org', 'active' => '1']], user: 'admin => alice@example.org', role: 'admin => user');
        $this->asAdmin();

        $entry = $this->getJson('/api/v2/logs/legacy-ui-activity')->assertOk()->json('data.0');

        $this->assertSame([
            'id' => (string) $id,
            'time' => '2025-10-18T10:00:00Z',
            'type' => 'success',
            'task' => 'A1B2C3',
            'user' => 'admin => alice@example.org',
            'role' => 'admin => user',
            'clientAddress' => '192.0.2.30',
            'messageKey' => 'mailbox_modified',
            'messageParameters' => ['alice@example.org'],
            'call' => ['mailbox', 'edit', ['username' => 'alice@example.org', 'active' => '1']],
        ], $entry);
    }

    public function test_a_plain_message_and_an_unparseable_row(): void
    {
        $this->legacyUiLog('info', 'logged_in_as', null);
        DB::table('logs')->insert(['type' => 'danger', 'task' => 'BAD000', 'msg' => '{not json', 'call' => 'also not json', 'user' => 'admin', 'role' => 'admin', 'remote' => '192.0.2.31', 'time' => 1760781601]);
        $this->asAdmin();

        $response = $this->getJson('/api/v2/logs/legacy-ui-activity')->assertOk();

        $response->assertJsonPath('meta.total', 2);
        $this->assertNull($response->json('data.0.messageKey'));
        $this->assertSame([], $response->json('data.0.messageParameters'));
        $this->assertSame([], $response->json('data.0.call'));
        $this->assertSame('logged_in_as', $response->json('data.1.messageKey'));
        $this->assertSame([], $response->json('data.1.call'));
    }

    public function test_order_cursor_and_exact_pages(): void
    {
        $ids = [];
        for ($i = 0; $i < 30; $i++) {
            $ids[] = $this->legacyUiLog('success', 'm'.$i, null, time: 1760781600 + intdiv($i, 3));
        }
        $this->asAdmin();

        $page = (array) $this->getJson('/api/v2/logs/legacy-ui-activity?limit=10')->json('data');
        $this->assertSame(['m29', 'm28'], array_column(array_slice($page, 0, 2), 'messageKey'));

        $next = $this->getJson('/api/v2/logs/legacy-ui-activity?limit=10&before='.end($page)['id'])->assertOk();
        $this->assertSame('m19', $next->json('data.0.messageKey'));
        $next->assertJsonPath('meta.total', 20);

        $range = $this->getJson('/api/v2/logs/legacy-ui-activity?limit=5&offset=10')->assertOk();
        $this->assertCount(5, $range->json('data'), 'the legacy LIMIT bug is not reproduced');
        $this->assertSame('m19', $range->json('data.0.messageKey'));

        $this->assertSame('m0', $this->getJson('/api/v2/logs/legacy-ui-activity?order=asc&limit=1')->json('data.0.messageKey'));
    }

    public function test_type_user_and_role_filters(): void
    {
        $this->legacyUiLog('success', 'a', null, user: 'admin', role: 'admin');
        $this->legacyUiLog('danger', 'b', null, user: 'reseller', role: 'domainadmin');
        $this->legacyUiLog('warning', 'c', null, user: 'alice@example.org', role: 'user');
        $this->asAdmin();

        $this->assertSame(['b'], array_column((array) $this->getJson('/api/v2/logs/legacy-ui-activity?type=DANGER')->json('data'), 'messageKey'));
        $this->assertSame(['c', 'b'], array_column((array) $this->getJson('/api/v2/logs/legacy-ui-activity?user=reseller&user=alice')->json('data'), 'messageKey'));
        $this->assertSame(['b'], array_column((array) $this->getJson('/api/v2/logs/legacy-ui-activity?role=domain')->json('data'), 'messageKey'));
    }

    public function test_from_and_to(): void
    {
        foreach ([0, 10, 20] as $offset) {
            $this->legacyUiLog('success', "t{$offset}", null, time: 1760781600 + $offset);
        }
        $this->asAdmin();

        $this->assertSame(['t10'], array_column((array) $this->getJson('/api/v2/logs/legacy-ui-activity?from=2025-10-18T10:00:05Z&to=2025-10-18T10:00:10Z')->json('data'), 'messageKey'));
    }

    public function test_the_search_runs_on_the_masked_entries(): void
    {
        $this->legacyUiLog('success', ['domain_modified', 'example.org'], ['domain', 'edit', ['domain' => 'example.org', 'password' => 'needle-secret']]);
        $this->legacyUiLog('success', ['domain_modified', 'needle.example'], ['domain', 'edit', ['domain' => 'needle.example']]);
        $this->legacyUiLog('success', 'other', null, user: 'NeedleUser');
        $this->asAdmin();

        $response = $this->getJson('/api/v2/logs/legacy-ui-activity?q=needle')->assertOk();

        $response->assertJsonPath('meta.total', 2);
        $this->assertSame(['other', 'domain_modified'], array_column((array) $response->json('data'), 'messageKey'));
        $this->getJson('/api/v2/logs/legacy-ui-activity?q=needle&limit=1&offset=1')->assertOk()->assertJsonPath('data.0.messageParameters', ['needle.example']);
    }

    public function test_a_search_for_a_json_escaped_value(): void
    {
        $this->legacyUiLog('success', ['note', 'Alice & Bob <team>'], null);
        $this->asAdmin();

        $this->getJson('/api/v2/logs/legacy-ui-activity?q='.urlencode('& Bob <'))->assertOk()->assertJsonPath('meta.total', 1);
    }
}
