<?php

namespace Tests\Feature\Quarantine;

use App\Auth\Role;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T078 (SC-010, FR-063): every state-changing operation of the feature runs once, singly
 * and in bulk, successfully and refused, on items whose subject, body, attachment name and
 * quick-action hash are known markers, and with a notification template that carries a marker.
 * Each request writes exactly one audit record per affected object, and no audit record and no
 * log line contains any marker.
 */
class AuditContentScanTest extends TestCase
{
    use QuarantineTestHelpers;

    private const SUBJECT = 'MARKER-SUBJ-7f3a';

    private const BODY = 'MARKER-BODY-91c2';

    private const ATTACHMENT = 'MARKER-ATTACH-55d1.pdf';

    private const TEMPLATE = 'MARKER-TPL-0b8e';

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
        $channel = Log::channel('stderr_json');
        $this->assertInstanceOf(\Illuminate\Log\Logger::class, $channel);
        $logger = $channel->getLogger();
        $this->assertInstanceOf(Logger::class, $logger);
        $logger->pushHandler($this->logs = new TestHandler);

        $this->mailcow()->domain('example.org');
        $this->quarantineMailbox('alice@example.org');
        $this->quarantineMailbox('bob@example.org');
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $this->mailcow()->domainAdmin('reseller', ['example.org'], ['quarantine' => 0], scheme: 'SSHA256');
    }

    private function message(): string
    {
        return implode("\r\n", [
            'From: Sender <news@example.net>',
            'To: alice@example.org',
            'Subject: '.self::SUBJECT,
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b1"',
            '',
            '--b1',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Hello '.self::BODY,
            '--b1',
            'Content-Type: application/pdf; name="'.self::ATTACHMENT.'"',
            'Content-Disposition: attachment; filename="'.self::ATTACHMENT.'"',
            'Content-Transfer-Encoding: base64',
            '',
            base64_encode('%PDF-1.4 '.self::BODY),
            '--b1--',
            '',
        ]);
    }

    private function marked(string $rcpt = 'alice@example.org'): int
    {
        return $this->item($rcpt, 'plain', ['msg' => $this->message(), 'subject' => self::SUBJECT]);
    }

    /**
     * Runs a request and asserts the number of audit records it wrote.
     *
     * @param  Closure(): TestResponse<Response>  $request
     */
    private function audited(int $records, Closure $request, int $status): void
    {
        $before = DB::table('restv2_audit_events')->count();
        $request()->assertStatus($status);
        $this->assertSame($before + $records, DB::table('restv2_audit_events')->count(), 'records written');
    }

    private function as(string $username, Role $role): void
    {
        $this->forgetCookies();
        $this->signInAs($username, $role);
    }

    public function test_no_marker_reaches_an_audit_record_or_a_log_line(): void
    {
        $items = [];

        for ($i = 0; $i < 9; $i++) {
            $items[] = $this->marked();
        }

        $hashes = DB::table('quarantine')->pluck('qhash')->map(fn (mixed $h): string => (string) $h)->all();

        $this->as('admin', Role::Admin);
        // Item actions: success and refusals.
        $this->audited(1, fn () => $this->send('POST', "/api/v2/quarantine/{$items[0]}/release"), 200);
        $this->audited(1, fn () => $this->send('POST', "/api/v2/quarantine/{$items[1]}/release", ['learnAsHam' => false]), 200);
        $this->audited(1, fn () => $this->send('POST', "/api/v2/quarantine/{$items[2]}/spam-learning"), 200);
        $this->audited(1, fn () => $this->send('DELETE', "/api/v2/quarantine/{$items[3]}"), 204);
        $this->audited(1, fn () => $this->send('DELETE', '/api/v2/quarantine/999999'), 404);
        $this->mailer->refuse();
        $this->audited(1, fn () => $this->send('POST', "/api/v2/quarantine/{$items[4]}/release"), 409);
        $this->mailer->accept();
        $this->audited(3, fn () => $this->send('POST', '/api/v2/quarantine/bulk-actions', ['action' => 'release', 'targets' => [['id' => (string) $items[5]], ['id' => (string) $items[6]], ['id' => '999999']]]), 200);
        $this->audited(2, fn () => $this->send('POST', '/api/v2/quarantine/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $items[7]], ['id' => (string) $items[7], 'version' => '"outdated"'], ['id' => (string) $items[8], 'version' => '"outdated"']]]), 200);
        $this->spamFilter->unreachable();
        $this->audited(1, fn () => $this->send('POST', "/api/v2/quarantine/{$items[8]}/spam-learning"), 503);

        // Settings, template check and the validate-only and preview requests (never audited).
        $this->audited(1, fn () => $this->send('PATCH', '/api/v2/quarantine/settings', ['notificationTemplate' => '<p>'.self::TEMPLATE.' {{ username }}</p>']), 200);
        $this->audited(1, fn () => $this->send('PATCH', '/api/v2/quarantine/settings', ['notificationTemplate' => '{% for x in '.self::TEMPLATE.' %}']), 422);
        $this->audited(0, fn () => $this->send('PATCH', '/api/v2/quarantine/settings?validateOnly=true', ['notificationTemplate' => '<p>'.self::TEMPLATE.'</p>']), 200);
        $this->audited(0, fn () => $this->send('POST', '/api/v2/quarantine/settings/template-preview', ['template' => '<p>'.self::TEMPLATE.'</p>']), 200);

        // Notification settings, spam scores, list entries, rate limits and buckets.
        $this->audited(1, fn () => $this->send('PATCH', '/api/v2/mailboxes/alice@example.org/quarantine-notification-settings', ['frequency' => 'daily']), 200);
        $this->audited(2, fn () => $this->send('POST', '/api/v2/quarantine-notification-settings/bulk-actions', ['frequency' => 'weekly', 'targets' => [['id' => 'alice@example.org'], ['id' => 'bob@example.org']]]), 200);
        $this->audited(1, fn () => $this->send('PUT', '/api/v2/mailboxes/alice@example.org/spam-score', ['junkScore' => 6, 'rejectScore' => 12]), 200);
        $this->audited(1, fn () => $this->send('DELETE', '/api/v2/mailboxes/alice@example.org/spam-score'), 204);
        $entry = 0;
        $this->audited(1, function () use (&$entry) {
            $response = $this->send('POST', '/api/v2/mailboxes/alice@example.org/spam-policy-entries', ['list' => 'block', 'pattern' => '*@spam.example']);
            $entry = (int) $response->json('data.id');

            return $response;
        }, 201);
        $this->audited(1, fn () => $this->send('POST', '/api/v2/mailboxes/alice@example.org/spam-policy-entries', ['list' => 'allow', 'pattern' => '*@spam.example']), 409);
        $this->audited(1, fn () => $this->send('DELETE', "/api/v2/mailboxes/alice@example.org/spam-policy-entries/{$entry}"), 204);
        $this->audited(1, fn () => $this->send('PUT', '/api/v2/domains/example.org/rate-limit', ['value' => 10, 'frame' => 'minute']), 200);
        $this->audited(1, fn () => $this->send('DELETE', '/api/v2/domains/example.org/rate-limit'), 204);
        $this->redis()->hSet('RLabc=', 'cnt', '1');
        $this->audited(1, fn () => $this->send('DELETE', '/api/v2/rate-limit-buckets/RLabc='), 204);

        // A bulk request refused as a whole: one record.
        $this->as('reseller', Role::DomainAdmin);
        $this->audited(1, fn () => $this->send('POST', '/api/v2/quarantine/bulk-actions', ['action' => 'delete', 'targets' => [['id' => (string) $items[8]]]]), 403);

        $audit = (string) json_encode(DB::table('restv2_audit_events')->get()->all());
        $logs = implode("\n", array_map(fn ($record): string => (string) json_encode([$record->message, $record->context, $record->extra]), $this->logs->getRecords()));

        foreach ([self::SUBJECT, self::BODY, self::ATTACHMENT, self::TEMPLATE, ...$hashes] as $marker) {
            $this->assertStringNotContainsString($marker, $audit, "audit records contain {$marker}");
            $this->assertStringNotContainsString($marker, $logs, "log lines contain {$marker}");
        }
    }
}
