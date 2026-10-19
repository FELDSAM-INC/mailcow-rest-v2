<?php

namespace Tests\Feature\Quarantine;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Quarantine\LargeMessage;
use Tests\Support\Quarantine\QuarantineTestHelpers;
use Tests\TestCase;

/**
 * Feature 006 T029 (FR-006, FR-016 to FR-018, AS-1.6, AS-1.7, AS-2.4): the whole message and single
 * attachments are always delivered as files to save that browsers never display, sniff or run;
 * the flags gate them; messages above the content size limit are refused; downloads are reads
 * (read-only keys may download) and are not audited.
 */
class DownloadQuarantineFilesTest extends TestCase
{
    use QuarantineTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSpamFilter();
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertSafeDownload(TestResponse $response): void
    {
        $this->assertStringStartsWith('attachment; filename="', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
    }

    public function test_the_whole_message_is_the_stored_bytes(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org', 'multipart-two-attachments', ['subject' => 'Invoice and logo']);

        $response = $this->get("/api/v2/quarantine/{$id}/message")->assertOk();

        $this->assertSame(LargeMessage::fixture('multipart-two-attachments'), $response->getContent());
        $this->assertSame('message/rfc822', $response->headers->get('Content-Type'));
        $this->assertSame("attachment; filename=\"Invoice and logo.eml\"; filename*=UTF-8''Invoice%20and%20logo.eml", $response->headers->get('Content-Disposition'));
        $this->assertSafeDownload($response);
    }

    public function test_attachments_are_the_decoded_bytes_as_octet_streams(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org', 'multipart-two-attachments');

        $pdf = $this->get("/api/v2/quarantine/{$id}/attachments/0")->assertOk();
        $this->assertStringStartsWith('%PDF-1.4', (string) $pdf->getContent());
        $this->assertSame('application/octet-stream', $pdf->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="invoice.pdf"', (string) $pdf->headers->get('Content-Disposition'));
        $this->assertSafeDownload($pdf);

        $png = $this->get("/api/v2/quarantine/{$id}/attachments/1")->assertOk();
        $this->assertStringStartsWith("\x89PNG", (string) $png->getContent());
    }

    public function test_html_and_svg_attachments_are_never_served_as_their_type(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $html = $this->item('alice@example.org', 'html-attachment');
        $svg = $this->item('alice@example.org', 'svg-attachment');
        $path = $this->item('alice@example.org', 'path-name-attachment');

        foreach ([$html, $svg] as $id) {
            $response = $this->get("/api/v2/quarantine/{$id}/attachments/0")->assertOk();
            $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
            $this->assertSafeDownload($response);
        }

        $response = $this->get("/api/v2/quarantine/{$path}/attachments/0")->assertOk();
        $this->assertStringContainsString('filename="....etcpasswd"', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_mailbox_user_without_quarantine_cannot_download_the_message(): void
    {
        $this->asQuarantineUser('alice@example.org', ['quarantine' => 0, 'quarantine_attachments' => 0]);
        $id = $this->item('alice@example.org');

        $this->getJson("/api/v2/quarantine/{$id}/message")
            ->assertForbidden()
            ->assertJsonPath('type', '/api/v2/problems/permission-flag-required')
            ->assertJsonPath('requiredFlags', ['quarantine']);
    }

    public function test_a_mailbox_user_without_attachments_cannot_download_either(): void
    {
        $this->asQuarantineUser('alice@example.org', ['quarantine' => 1, 'quarantine_attachments' => 0]);
        $id = $this->item('alice@example.org', 'multipart-two-attachments');

        $this->getJson("/api/v2/quarantine/{$id}/message")->assertForbidden()->assertJsonPath('requiredFlags', ['quarantineAttachments']);
        $this->getJson("/api/v2/quarantine/{$id}/attachments/0")->assertForbidden()->assertJsonPath('requiredFlags', ['quarantineAttachments']);
    }

    public function test_an_unknown_position_is_not_found(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org', 'multipart-two-attachments');

        $this->getJson("/api/v2/quarantine/{$id}/attachments/9")->assertNotFound();
    }

    public function test_a_message_above_the_content_limit_is_refused(): void
    {
        $this->asQuarantineUser('alice@example.org');
        $id = $this->item('alice@example.org', overrides: ['msg' => LargeMessage::build()]);

        foreach (["/api/v2/quarantine/{$id}/message", "/api/v2/quarantine/{$id}/attachments/0"] as $uri) {
            $this->getJson($uri)
                ->assertStatus(409)
                ->assertJsonPath('type', '/api/v2/problems/quarantine-message-too-large')
                ->assertJsonPath('size', LargeMessage::LIMIT + 1)
                ->assertJsonPath('limit', LargeMessage::LIMIT);
        }
    }

    public function test_an_admins_read_only_key_may_download_and_nothing_is_audited(): void
    {
        $this->quarantineMailbox('alice@example.org');
        $this->mailcow()->admin('admin', 'Passw0rd!', 'SSHA256');
        $key = $this->apiKey('admin', 'readOnly');
        $id = $this->item('alice@example.org', 'multipart-two-attachments');

        $this->get("/api/v2/quarantine/{$id}/message", $this->keyHeader($key))->assertOk();
        $this->get("/api/v2/quarantine/{$id}/attachments/1", $this->keyHeader($key))->assertOk();

        $this->assertSame(0, DB::table('restv2_audit_events')->count());
    }
}
