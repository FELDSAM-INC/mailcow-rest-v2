<?php

namespace Tests\Unit\Services;

use App\Services\MassMail\MassMailMessageFactory;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Feature 013 T065 (research R18, FR-076): each mass mail message is addressed only to its
 * recipient and carries legacy's mailer identification; with an HTML body both bodies are
 * alternatives.
 */
class MassMailMessageFactoryTest extends TestCase
{
    private function message(?string $html = null, string $subject = 'Maintenance tonight'): string
    {
        return (new MassMailMessageFactory)->build(
            '01JABCDEFGHJKMNPQRSTVWXYZ0',
            42,
            'noreply@mail.example.org',
            'alice@example.org',
            $subject,
            "Dear user,\nwe update the server.\n",
            $html,
            CarbonImmutable::parse('2026-10-19 10:00:00', 'UTC'),
        );
    }

    public function test_a_text_message_has_the_headers_of_legacy_s_mass_mail(): void
    {
        $message = $this->message();

        $this->assertMatchesRegularExpression('/^From: noreply@mail\.example\.org\r$/m', $message);
        $this->assertMatchesRegularExpression('/^To: alice@example\.org\r$/m', $message);
        $this->assertSame(1, preg_match_all('/^To: /m', $message));
        $this->assertMatchesRegularExpression('/^Subject: Maintenance tonight\r$/m', $message);
        $this->assertMatchesRegularExpression('/^Date: Mon, 19 Oct 2026 10:00:00 \+0000\r$/m', $message);
        $this->assertMatchesRegularExpression('/^Message-ID: <01JABCDEFGHJKMNPQRSTVWXYZ0\.42@mail\.example\.org>\r$/m', $message);
        $this->assertMatchesRegularExpression('/^X-Mailer: MooMassMail\r$/m', $message);
        $this->assertMatchesRegularExpression('#^Content-Type: text/plain; charset=utf-8#m', $message);
        $this->assertStringContainsString("Dear user,\r\nwe update the server.", $message);
    }

    public function test_an_html_body_makes_a_multipart_alternative(): void
    {
        $message = $this->message('<p>Dear user</p>');

        $this->assertMatchesRegularExpression('#^Content-Type: multipart/alternative; boundary=#m', $message);
        $this->assertStringContainsString('Content-Type: text/plain; charset=utf-8', $message);
        $this->assertStringContainsString('Content-Type: text/html; charset=utf-8', $message);
        $this->assertStringContainsString('<p>Dear user</p>', $message);
    }

    public function test_a_unicode_subject_is_encoded(): void
    {
        $message = $this->message(null, 'Údržba serveru 🛠');

        $this->assertMatchesRegularExpression('/^Subject: =\?utf-8\?/mi', $message);
    }
}
