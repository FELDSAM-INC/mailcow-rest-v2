<?php

namespace Tests\Unit\Logs;

use App\Services\Logs\Base64Message;
use PHPUnit\Framework\TestCase;

/**
 * Feature 011 T045 (FR-023, research R12): messages stored with a `base64,` prefix.
 */
class Base64MessageTest extends TestCase
{
    public function test_a_valid_base64_message_is_decoded(): void
    {
        $this->assertSame('mail.example.org - Certificate renewed', Base64Message::decode('base64,'.base64_encode('mail.example.org - Certificate renewed')));
    }

    public function test_text_that_is_not_base64_keeps_its_text_without_the_prefix(): void
    {
        $this->assertSame('mail.example.org - Renewal failed: rate limited', Base64Message::decode('base64,mail.example.org - Renewal failed: rate limited'));
    }

    public function test_base64_of_invalid_utf8_keeps_the_text(): void
    {
        $encoded = base64_encode("\xff\xfe\xfa");

        $this->assertSame($encoded, Base64Message::decode('base64,'.$encoded));
    }

    public function test_other_messages_are_unchanged(): void
    {
        $this->assertSame('plain message', Base64Message::decode('plain message'));
    }
}
