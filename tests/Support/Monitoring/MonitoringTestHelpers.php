<?php

namespace Tests\Support\Monitoring;

use App\Auth\Role;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\Support\ApiKeys\ApiKeys;
use Tests\Support\Mailcow\MailcowFactory;

/**
 * Callers and audit lookups shared by the tests of feature 011. Used in Tests\TestCase subclasses.
 */
trait MonitoringTestHelpers
{
    use ApiKeys;

    protected function asAdmin(string $username = 'admin'): void
    {
        if (! DB::table('admin')->where('username', $username)->exists()) {
            $this->mailcow()->admin($username, MailcowFactory::PASSWORD);
        }

        $this->signInAs($username, Role::Admin);
    }

    protected function asDomainAdmin(string $username = 'reseller'): void
    {
        if (! DB::table('admin')->where('username', $username)->exists()) {
            $this->mailcow()->domain('example.org');
            $this->mailcow()->domainAdmin($username, ['example.org']);
        }

        $this->signInAs($username, Role::DomainAdmin);
    }

    /**
     * The `X-API-Key` header of a key owned by an administrator.
     *
     * @return array<string, string>
     */
    protected function adminKey(string $accessLevel = 'readWrite'): array
    {
        if (! DB::table('admin')->where('username', 'admin')->exists()) {
            $this->mailcow()->admin('admin', MailcowFactory::PASSWORD);
        }

        return $this->keyHeader($this->apiKey('admin', $accessLevel));
    }

    /**
     * The headers of a state-changing session request.
     *
     * @return array<string, string>
     */
    protected function csrf(): array
    {
        return ['X-CSRF-Token' => $this->csrfToken()];
    }

    /**
     * @return list<stdClass>
     */
    protected function auditRecords(?string $eventType = null): array
    {
        $query = DB::table('restv2_audit_events')->orderBy('occurred_at')->orderBy('id');

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }

        return array_values($query->get()->all());
    }

    protected function onlyAuditRecord(?string $eventType = null): stdClass
    {
        $records = $this->auditRecords($eventType);
        $this->assertCount(1, $records, 'expected exactly one audit record: '.json_encode($records));

        return $records[0];
    }

    /**
     * @return array<string, mixed>
     */
    protected function details(stdClass $record): array
    {
        return (array) json_decode((string) $record->details, true);
    }
}
