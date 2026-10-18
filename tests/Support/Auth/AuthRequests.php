<?php

namespace Tests\Support\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Mailcow\MailcowFactory;

/**
 * Sign-in requests and audit lookups shared by the authentication tests. Used in Tests\TestCase
 * subclasses.
 */
trait AuthRequests
{
    /**
     * `POST /session` with the session's anti-forgery token.
     *
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function passwordSignIn(string $username, string $password = MailcowFactory::PASSWORD, ?string $role = null, array $headers = []): TestResponse
    {
        $body = ['username' => $username, 'password' => $password];

        if ($role !== null) {
            $body['role'] = $role;
        }

        return $this->postJson('/api/v2/session', $body, ['X-CSRF-Token' => $this->csrfToken()] + $headers);
    }

    /**
     * `POST /session/pending-sign-in/second-factor` with the session's anti-forgery token.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    protected function verifySecondFactor(array $body): TestResponse
    {
        return $this->postJson('/api/v2/session/pending-sign-in/second-factor', $body, ['X-CSRF-Token' => $this->csrfToken()]);
    }

    /**
     * The audit records of an event type, oldest first.
     *
     * @return list<stdClass>
     */
    protected function auditRecords(string $eventType): array
    {
        return DB::table('restv2_audit_events')
            ->where('event_type', $eventType)
            ->orderBy('occurred_at')
            ->get()
            ->map(function (stdClass $row): stdClass {
                $row->details = json_decode((string) $row->details, true);

                return $row;
            })
            ->all();
    }

    /**
     * The only audit record of an event type.
     */
    protected function onlyAuditRecord(string $eventType): stdClass
    {
        $records = $this->auditRecords($eventType);
        $this->assertCount(1, $records, "Expected exactly one {$eventType} audit record.");

        return $records[0];
    }
}
