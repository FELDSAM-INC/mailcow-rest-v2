<?php

namespace Tests\Unit\IdentityProvider;

use App\Services\IdentityProvider\SynchronisationLogParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * T016 (research R12, FR-062): every log text of the legacy synchronisation jobs is classified, and
 * the hand-written log sample yields the documented status at fixed clock times.
 */
class SynchronisationLogParserTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../fixtures/cron-log/';

    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $data = json_decode((string) file_get_contents(self::FIXTURES.$name), true);
        self::assertIsArray($data);

        return $data;
    }

    public function test_every_message_of_the_release_is_classified(): void
    {
        $messages = self::fixture('2026-09a-messages.json')['messages'];
        $this->assertNotEmpty($messages);

        $expected = [
            'DONE!' => SynchronisationLogParser::COMPLETED,
            'Sync not ready (' => SynchronisationLogParser::TICK,
            'Sync is already running' => SynchronisationLogParser::ALREADY_RUNNING,
            'Creating user ' => SynchronisationLogParser::PROGRESS,
            'Syncing attributes for user ' => SynchronisationLogParser::PROGRESS,
            'Skipping user ' => SynchronisationLogParser::PROGRESS,
            'Using default template for user ' => SynchronisationLogParser::PROGRESS,
            'No matching attribute mapping found for user ' => SynchronisationLogParser::PROGRESS,
            'No email address in keycloak found for user ' => SynchronisationLogParser::PROGRESS,
            'Could not create user ' => SynchronisationLogParser::PROGRESS,
            'Received HTTP ' => SynchronisationLogParser::FAILURE,
            'Received malformed response from keycloak api' => SynchronisationLogParser::FAILURE,
            '' => SynchronisationLogParser::FAILURE,
        ];

        foreach ($messages as $message) {
            $text = $message['exact'] ? $message['prefix'] : $message['prefix'].'example';
            $class = SynchronisationLogParser::classify(['priority' => $message['priority'], 'message' => $text]);

            $this->assertArrayHasKey($message['prefix'], $expected, "unexpected log text \"{$message['prefix']}\" ({$message['source']}:{$message['line']}): extend the classification");
            $this->assertSame($expected[$message['prefix']], $class, "{$message['task']}: {$message['priority']} \"{$text}\"");
        }
    }

    public function test_an_unknown_info_or_warning_text_is_not_a_failure(): void
    {
        $this->assertSame(SynchronisationLogParser::OTHER, SynchronisationLogParser::classify(['priority' => 'info', 'message' => 'Something new']));
        $this->assertSame(SynchronisationLogParser::FAILURE, SynchronisationLogParser::classify(['priority' => 'err', 'message' => 'SQLSTATE[HY000] [2002] gone']));
    }

    /**
     * @return iterable<string, array{string, array{lastCompletedAt: string|null, lastFailedAt: string|null, state: string, nextRunExpectedAt: string|null}}>
     */
    public static function clockTimes(): iterable
    {
        yield 'before any entry' => ['09:59:00', ['lastCompletedAt' => null, 'lastFailedAt' => null, 'state' => 'unknown', 'nextRunExpectedAt' => null]];
        yield 'waiting after run 1 (next minute rounded up)' => ['10:10:00', ['lastCompletedAt' => '10:00:08', 'lastFailedAt' => null, 'state' => 'waiting', 'nextRunExpectedAt' => '10:16:00']];
        yield 'run 2 in progress' => ['10:16:02', ['lastCompletedAt' => '10:00:08', 'lastFailedAt' => null, 'state' => 'running', 'nextRunExpectedAt' => '10:16:00']];
        yield 'waiting after run 2' => ['10:20:00', ['lastCompletedAt' => '10:16:03', 'lastFailedAt' => null, 'state' => 'waiting', 'nextRunExpectedAt' => '10:32:00']];
        yield 'run 3 failed' => ['10:32:30', ['lastCompletedAt' => '10:16:03', 'lastFailedAt' => '10:32:00', 'state' => 'waiting', 'nextRunExpectedAt' => '10:33:00']];
        yield 'run 4 in progress after the failure' => ['10:33:40', ['lastCompletedAt' => '10:16:03', 'lastFailedAt' => '10:32:00', 'state' => 'running', 'nextRunExpectedAt' => '10:33:00']];
        yield 'run 4 never completed' => ['10:40:00', ['lastCompletedAt' => '10:16:03', 'lastFailedAt' => '10:32:00', 'state' => 'overdue', 'nextRunExpectedAt' => '10:33:00']];
    }

    /**
     * @param  array{lastCompletedAt: string|null, lastFailedAt: string|null, state: string, nextRunExpectedAt: string|null}  $expected
     */
    #[DataProvider('clockTimes')]
    public function test_the_sample_log_at_fixed_clock_times(string $clock, array $expected): void
    {
        $sample = self::fixture('2026-09a.json');
        $now = CarbonImmutable::parse('2026-10-18 '.$clock, 'UTC');

        $status = SynchronisationLogParser::derive($sample['entries'], $sample['task'], (int) $sample['syncInterval'], $now);

        $format = fn (?CarbonImmutable $t): ?string => $t?->utc()->format('H:i:s');
        $this->assertSame($expected, [
            'lastCompletedAt' => $format($status['lastCompletedAt']),
            'lastFailedAt' => $format($status['lastFailedAt']),
            'state' => $status['state'],
            'nextRunExpectedAt' => $format($status['nextRunExpectedAt']),
        ]);
    }

    public function test_a_run_that_ends_on_a_full_minute_is_followed_exactly_one_interval_later(): void
    {
        $done = CarbonImmutable::parse('2026-10-18 11:00:00', 'UTC');
        $entries = [['time' => $done->getTimestamp(), 'priority' => 'info', 'task' => 'LDAP Sync', 'message' => 'DONE!']];

        $status = SynchronisationLogParser::derive($entries, 'LDAP Sync', 15, $done->addMinutes(5));

        $this->assertSame('11:15:00', $status['nextRunExpectedAt']?->format('H:i:s'));
        $this->assertSame('waiting', $status['state']);
    }

    public function test_the_other_jobs_entries_are_ignored(): void
    {
        $sample = self::fixture('2026-09a.json');
        $now = CarbonImmutable::parse('2026-10-18 10:30:00', 'UTC');

        $status = SynchronisationLogParser::derive($sample['entries'], 'LDAP Sync', 15, $now);

        $this->assertSame('10:20:01', $status['lastCompletedAt']?->format('H:i:s'));
        $this->assertSame('10:25:00', $status['lastFailedAt']?->format('H:i:s'));
        $this->assertSame('overdue', $status['state'], 'the failure at 10:25 expected a run at 10:26');
    }
}
