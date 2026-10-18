<?php

namespace Tests\Feature\SyncJobs;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SyncJobs\SyncJobTestHelpers;
use Tests\TestCase;

/**
 * Feature 008 T035 (FR-017, US2 AS-4 to AS-8, clarifications 4 and 5): every invalid member of a
 * creation is refused with its rule code, all at once, and nothing is clamped or stored; values at
 * the boundaries and the documented edge values are accepted, and the stored values are normalised
 * as legacy normalises them.
 */
class CreateSyncJobValidationTest extends TestCase
{
    use SyncJobTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->asSyncUser('alice@example.org');
    }

    /**
     * @return array<string, array{string, int, int, int}>
     */
    public static function ranges(): array
    {
        return [
            'interval below' => ['intervalMinutes', 0, 1, 43800],
            'interval above' => ['intervalMinutes', 43801, 1, 43800],
            'port below' => ['remotePort', 0, 1, 65535],
            'port above' => ['remotePort', 65536, 1, 65535],
            'age above' => ['maxAgeDays', 32001, 0, 32000],
            'age below' => ['maxAgeDays', -1, 0, 32000],
            'speed above' => ['maxBytesPerSecond', 125000001, 0, 125000000],
            'remote timeout below' => ['remoteTimeoutSeconds', 0, 1, 32000],
            'remote timeout above' => ['remoteTimeoutSeconds', 32001, 1, 32000],
            'local timeout below' => ['localTimeoutSeconds', 0, 1, 32000],
            'local timeout above' => ['localTimeoutSeconds', 32001, 1, 32000],
        ];
    }

    #[DataProvider('ranges')]
    public function test_an_invalid_out_of_range_number_is_refused_with_its_range(string $member, int $value, int $min, int $max): void
    {
        $this->withoutRequestValidation();

        $this->createSyncJob(self::syncJobBody([$member => $value]))->assertUnprocessable()
            ->assertJsonPath("errors.{$member}.0.code", 'outOfRange')
            ->assertJsonPath("errors.{$member}.0.params", ['min' => $min, 'max' => $max]);

        $this->assertSame(0, $this->syncJobCount());
    }

    public function test_the_boundaries_are_accepted_and_stored_as_sent(): void
    {
        $low = $this->createSyncJob(self::syncJobBody(['intervalMinutes' => 1, 'remotePort' => 1, 'maxAgeDays' => 0, 'maxBytesPerSecond' => 0, 'remoteTimeoutSeconds' => 1, 'localTimeoutSeconds' => 1]))->assertCreated();
        $high = $this->createSyncJob(self::syncJobBody(['remoteUser' => 'other', 'intervalMinutes' => 43800, 'remotePort' => 65535, 'maxAgeDays' => 32000, 'maxBytesPerSecond' => 125000000, 'remoteTimeoutSeconds' => 32000, 'localTimeoutSeconds' => 32000]))->assertCreated();

        $row = $this->syncJobRow((int) $high->json('data.id'));
        $this->assertSame([43800, 65535, 32000, '125000000', 32000, 32000], [(int) $row?->mins_interval, (int) $row?->port1, (int) $row?->maxage, $row?->maxbytespersecond, (int) $row?->timeout1, (int) $row?->timeout2]);
        $this->assertSame(1, $low->json('data.intervalMinutes'));
    }

    public function test_invalid_types_and_null_are_refused(): void
    {
        $this->withoutRequestValidation();

        $response = $this->createSyncJob(self::syncJobBody(['intervalMinutes' => '20', 'active' => 'true', 'dryRun' => 1, 'exclude' => null, 'remotePort' => 993.5]))->assertUnprocessable();

        $this->assertSame(['invalidType', ['expected' => 'integer']], [$response->json('errors.intervalMinutes.0.code'), $response->json('errors.intervalMinutes.0.params')]);
        $this->assertSame(['invalidType', ['expected' => 'boolean']], [$response->json('errors.active.0.code'), $response->json('errors.active.0.params')]);
        $this->assertSame('invalidType', $response->json('errors.dryRun.0.code'));
        $this->assertSame('invalidType', $response->json('errors.exclude.0.code'));
        $this->assertSame('invalidType', $response->json('errors.remotePort.0.code'));
        $this->assertSame(0, $this->syncJobCount());
    }

    public function test_the_documented_edge_values_are_accepted(): void
    {
        $response = $this->createSyncJob(self::syncJobBody(['encryption' => 'plain', 'remoteHost' => '192.0.2.10', 'destinationSubfolder' => '', 'customParameters' => '', 'exclude' => '']))->assertCreated();

        $this->assertSame('plain', $response->json('data.encryption'));
        $this->assertSame('192.0.2.10', $response->json('data.remoteHost'));
        $this->assertSame('', $response->json('data.exclude'));
        $this->assertSame('', $this->syncJobRow((int) $response->json('data.id'))?->exclude);
    }

    public function test_invalid_lengths_enumerations_and_missing_members_are_refused(): void
    {
        $this->withoutRequestValidation();

        $this->createSyncJob(self::syncJobBody(['encryption' => 'tls']))->assertUnprocessable()
            ->assertJsonPath('errors.encryption.0.code', 'notInList')
            ->assertJsonPath('errors.encryption.0.params.allowed', ['ssl', 'starttls', 'plain']);

        $tooLong = $this->createSyncJob(self::syncJobBody([
            'remoteHost' => str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 63).'.example',
            'remoteUser' => str_repeat('u', 256),
            'remotePassword' => str_repeat('p', 256),
            'destinationSubfolder' => str_repeat('s', 256),
            'exclude' => str_repeat('e', 501),
            'customParameters' => '--maxsize='.str_repeat('1', 503),
        ]))->assertUnprocessable();

        foreach (['remoteHost' => 255, 'remoteUser' => 255, 'remotePassword' => 255, 'destinationSubfolder' => 255, 'exclude' => 500, 'customParameters' => 512] as $member => $max) {
            $this->assertSame(['tooLong', ['max' => $max]], [$tooLong->json("errors.{$member}.0.code"), $tooLong->json("errors.{$member}.0.params")], $member);
        }

        $missing = $this->createSyncJob(['remotePassword' => ''])->assertUnprocessable();
        foreach (['remoteHost', 'remotePort', 'remoteUser', 'remotePassword'] as $member) {
            $this->assertSame('required', $missing->json("errors.{$member}.0.code"), $member);
        }

        $this->assertSame(0, $this->syncJobCount());
    }

    public function test_an_invalid_host_pattern_or_custom_option_is_refused(): void
    {
        $this->createSyncJob(self::syncJobBody(['remoteHost' => '2001:db8::1']))->assertUnprocessable()
            ->assertJsonPath('errors.remoteHost.0.code', 'invalidFormat')
            ->assertJsonPath('errors.remoteHost.0.params', ['format' => 'hostName']);
        $this->createSyncJob(self::syncJobBody(['exclude' => '(']))->assertUnprocessable()
            ->assertJsonPath('errors.exclude.0.code', 'invalidRegex');

        $custom = $this->createSyncJob(self::syncJobBody(['customParameters' => '--bogus=1 --maxsize 5']))->assertUnprocessable();
        $this->assertSame([['optionNotAllowed', ['option' => 'bogus']], ['optionContainsSpace', ['token' => 'maxsize 5']]], array_map(
            fn (array $v): array => [$v['code'], $v['params']],
            (array) $custom->json('errors.customParameters'),
        ));

        $this->createSyncJob(self::syncJobBody(['customParameters' => '--showpasswords']))->assertUnprocessable()
            ->assertJsonPath('errors.customParameters.0.code', 'optionNotAllowed')
            ->assertJsonPath('errors.customParameters.0.params', ['option' => 'showpasswords']);

        $this->assertSame(0, $this->syncJobCount());
    }

    public function test_an_invalid_request_names_every_bad_member_at_once(): void
    {
        $this->withoutRequestValidation();

        $response = $this->createSyncJob([
            'remoteHost' => '2001:db8::1', 'remotePort' => 0, 'remoteUser' => 'a', 'remotePassword' => 'x',
            'exclude' => '(', 'customParameters' => '--showpasswords --maxsize 10', 'intervalMinutes' => 0,
        ])->assertUnprocessable();

        $this->assertEqualsCanonicalizing(['remoteHost', 'remotePort', 'exclude', 'customParameters', 'intervalMinutes'], array_keys((array) $response->json('errors')));
        $this->assertSame(['optionNotAllowed', 'optionContainsSpace'], array_column((array) $response->json('errors.customParameters'), 'code'));
        $this->assertSame(0, $this->syncJobCount());
    }

    public function test_the_host_is_lower_cased_the_parameters_trimmed_and_an_empty_exclusion_kept(): void
    {
        $id = (int) $this->createSyncJob(self::syncJobBody(['remoteHost' => 'IMAP.Example.COM', 'customParameters' => '  --maxsize=1 ', 'exclude' => '']))->assertCreated()->json('data.id');

        $row = $this->syncJobRow($id);
        $this->assertSame('imap.example.com', $row?->host1);
        $this->assertSame('--maxsize=1', $row?->custom_params);
        $this->assertSame('', $row?->exclude);
    }
}
