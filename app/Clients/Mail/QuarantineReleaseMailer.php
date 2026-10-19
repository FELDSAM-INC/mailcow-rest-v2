<?php

namespace App\Clients\Mail;

use Symfony\Component\Mime\RawMessage;

/**
 * Delivery of a released quarantine item to its recipient mailbox through mailcow's quarantine
 * submission (Postfix port 590, which bypasses the spam filter; feature 006 research R3). Faked in
 * tests (tests/Support/Mail/FakeQuarantineReleaseMailer.php).
 */
interface QuarantineReleaseMailer
{
    /**
     * Sends the message with the given envelope. Never throws: the mail server's answer is
     * classified as accepted, refused (`5xx`) or a temporary failure.
     */
    public function deliver(RawMessage $message, string $sender, string $recipient): DeliveryOutcome;
}
