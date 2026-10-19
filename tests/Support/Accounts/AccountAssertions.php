<?php

namespace Tests\Support\Accounts;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Row and audit assertions shared by the tests of feature 007 (T004). Used in Tests\TestCase
 * subclasses.
 */
trait AccountAssertions
{
    /** Byte offset of the test log when the test started capturing it. */
    private ?int $logOffset = null;

    /**
     * The `admin` row of an account: role, status and the two forced flags as legacy stores them.
     */
    protected function assertAdminRow(string $username, int $superadmin, int $active, bool $forcePasswordChange = false, bool $forceSecondFactorSetup = false): void
    {
        $row = DB::table('admin')->where('username', $username)->first();
        $this->assertInstanceOf(stdClass::class, $row, "No admin row for {$username}.");
        $this->assertSame($superadmin, (int) $row->superadmin, 'superadmin');
        $this->assertSame($active, (int) $row->active, 'active');
        $attributes = (array) json_decode((string) $row->attributes, true);
        $this->assertSame($forcePasswordChange ? '1' : '0', (string) ($attributes['force_pw_update'] ?? '0'), 'force_pw_update');
        $this->assertSame($forceSecondFactorSetup ? '1' : '0', (string) ($attributes['force_tfa'] ?? '0'), 'force_tfa');
    }

    /**
     * The `domain_admins` rows of a username, ordered by domain: `[domain, active]` or
     * `[domain, active, created]`.
     *
     * @param  list<array{0: string, 1: int, 2?: string}>  $expected
     */
    protected function assertAssignments(string $username, array $expected): void
    {
        $rows = DB::table('domain_admins')->where('username', $username)->orderBy('domain')->get();
        $actual = [];

        foreach ($rows as $index => $row) {
            $entry = [(string) $row->domain, (int) $row->active];

            if (isset($expected[$index][2])) {
                $entry[] = (string) $row->created;
            }

            $actual[] = $entry;
        }

        $this->assertSame($expected, $actual);
    }

    /**
     * The `da_acl` row of a username (legacy column names), or null for no row.
     *
     * @param  array<string, int>|null  $flags  every column, or a subset to compare
     */
    protected function assertDaAcl(string $username, ?array $flags): void
    {
        $row = DB::table('da_acl')->where('username', $username)->first();

        if ($flags === null) {
            $this->assertNull($row, "Expected no da_acl row for {$username}.");

            return;
        }

        $this->assertInstanceOf(stdClass::class, $row, "No da_acl row for {$username}.");
        $stored = array_map('intval', array_diff_key((array) $row, ['username' => true]));
        $actual = [];

        foreach (array_keys($flags) as $column) {
            $actual[$column] = $stored[$column] ?? null;
        }

        $this->assertSame($flags, $actual);
    }

    /**
     * Nothing of the account remains: no row in `admin`, `domain_admins`, `da_acl`, `tfa`, `fido2`,
     * `da_sso` or `restv2_preferences`, and no unrevoked API key.
     */
    protected function assertNothingLeftFor(string $username): void
    {
        foreach (['admin', 'domain_admins', 'da_acl', 'tfa', 'fido2', 'da_sso'] as $table) {
            $this->assertFalse(DB::table($table)->where('username', $username)->exists(), "{$table} still has rows of {$username}.");
        }

        $this->assertFalse(DB::table('restv2_preferences')->where('account_kind', 'admin')->where('username', $username)->exists(), "restv2_preferences still has a row of {$username}.");
        $this->assertFalse(DB::table('restv2_api_keys')->where('owner_username', $username)->whereNull('revoked_at')->exists(), "{$username} still owns unrevoked API keys.");
    }

    /**
     * The only audit record of an event type, outcome and target, with the expected detail subset
     * and impersonated account (`[username, role]`, or null outside an impersonation).
     *
     * @param  array<string, mixed>  $detailsSubset
     * @param  array{0: string, 1: string}|null  $impersonated
     */
    protected function assertAudit(string $eventType, string $outcome, ?string $targetType, ?string $targetName, array $detailsSubset = [], ?array $impersonated = null): stdClass
    {
        $query = DB::table('restv2_audit_events')->where('event_type', $eventType)->where('outcome', $outcome);

        if ($targetType !== null) {
            $query->where('target_type', $targetType);
        }

        if ($targetName !== null) {
            $query->where(fn ($q) => $q->where('target_id', $targetName)->orWhere('target_name', $targetName));
        }

        $records = $query->orderBy('occurred_at')->orderBy('id')->get();
        $this->assertCount(1, $records, "Expected exactly one {$outcome} {$eventType} record for {$targetType} {$targetName}, found ".count($records).'.');
        $record = $records[0];
        $details = (array) json_decode((string) $record->details, true);

        foreach ($detailsSubset as $key => $value) {
            $this->assertArrayHasKey($key, $details, "The {$eventType} record lacks details.{$key}.");
            $this->assertEquals($value, $details[$key], "details.{$key}");
        }

        if ($impersonated === null) {
            $this->assertNull($record->impersonated_username);
            $this->assertNull($record->impersonated_role);
        } else {
            $this->assertSame($impersonated, [$record->impersonated_username, $record->impersonated_role]);
        }

        $record->details = $details;

        return $record;
    }

    /**
     * Starts capturing the test log, so that assertNoSecretIn() scans only this test's lines.
     */
    protected function captureLog(): void
    {
        $log = storage_path('logs/testing.log');
        $this->logOffset = is_file($log) ? (int) filesize($log) : 0;
    }

    /**
     * No audit record and no log line written since captureLog() contains any of the secrets.
     */
    protected function assertNoSecretIn(string ...$secrets): void
    {
        $log = storage_path('logs/testing.log');
        $logged = $this->logOffset !== null && is_file($log) ? (string) file_get_contents($log, offset: $this->logOffset) : '';
        $audit = (string) json_encode(DB::table('restv2_audit_events')->get(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($secrets as $secret) {
            $this->assertNotSame('', $secret);
            $this->assertStringNotContainsString($secret, $audit, 'An audit record contains a secret.');
            $this->assertStringNotContainsString($secret, $logged, 'The log contains a secret.');
        }
    }
}
