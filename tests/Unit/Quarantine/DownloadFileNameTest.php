<?php

namespace Tests\Unit\Quarantine;

use App\Services\Quarantine\DownloadFileName;
use PHPUnit\Framework\TestCase;

/**
 * Feature 006 T025 (research R6, FR-016, FR-017): safe file names of downloads, and a
 * `Content-Disposition` that always says `attachment` with an ASCII `filename` and a UTF-8
 * `filename*`.
 */
class DownloadFileNameTest extends TestCase
{
    public function test_path_separators_are_removed_from_attachment_names(): void
    {
        $this->assertSame('....etcpasswd', DownloadFileName::forAttachment('../../etc/passwd', 0));
        $this->assertSame('a..b', DownloadFileName::forAttachment('a\\..\\b', 0));
    }

    public function test_control_characters_are_removed(): void
    {
        $this->assertSame('invoice.pdf', DownloadFileName::forAttachment("in\x00vo\x1Fice\r\n.pdf", 0));
        $this->assertSame('report.txt', DownloadFileName::forAttachment("  report\x7F.txt  ", 0));
    }

    public function test_attachment_names_are_not_shortened(): void
    {
        $long = str_repeat('a', 80).'.pdf';

        $this->assertSame($long, DownloadFileName::forAttachment($long, 0));
    }

    public function test_an_empty_attachment_name_gets_its_position(): void
    {
        $this->assertSame('attachment-3', DownloadFileName::forAttachment('', 3));
        $this->assertSame('attachment-1', DownloadFileName::forAttachment("/\x00 ", 1));
    }

    public function test_the_message_name_comes_from_the_subject(): void
    {
        $this->assertSame('Invoice and logo.eml', DownloadFileName::forMessage('Invoice and logo', 'ABC123'));
        $this->assertSame('Hello world.eml', DownloadFileName::forMessage('<b>Hello</b> world', 'ABC123'));
        $this->assertSame('evilname.eml', DownloadFileName::forMessage("\"evil/name\"\r\n", 'ABC123'));
    }

    public function test_the_message_name_is_cut_to_30_characters_multibyte_safe(): void
    {
        $name = DownloadFileName::forMessage(str_repeat('ž', 40), 'ABC123');

        $this->assertSame(str_repeat('ž', 30).'.eml', $name);
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
    }

    public function test_an_empty_subject_gives_the_queue_id(): void
    {
        $this->assertSame('4XyZ1q2w3eR.eml', DownloadFileName::forMessage('', '4XyZ1q2w3eR'));
        $this->assertSame('4XyZ1q2w3eR.eml', DownloadFileName::forMessage(null, '4XyZ1q2w3eR'));
        $this->assertSame('4XyZ1q2w3eR.eml', DownloadFileName::forMessage('<br>', '4XyZ1q2w3eR'));
    }

    public function test_the_disposition_has_an_ascii_and_a_utf8_name(): void
    {
        $this->assertSame("attachment; filename=\"invoice.pdf\"; filename*=UTF-8''invoice.pdf", DownloadFileName::disposition('invoice.pdf'));

        $header = DownloadFileName::disposition('Grüße 100%.pdf');

        $this->assertStringStartsWith('attachment; filename="', $header);
        $this->assertMatchesRegularExpression('/^attachment; filename="[\x20-\x7E]+"; filename\*=UTF-8\'\'Gr%C3%BC%C3%9Fe%20100%25\.pdf$/', $header);
        $this->assertStringNotContainsString('%"', $header);
    }
}
