<?php

namespace App\Clients\Mail;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;
use Throwable;

/**
 * Releases over SMTP to Postfix's quarantine submission (feature 006 research R3), on Symfony
 * Mailer's ESMTP transport: `EHLO quarantine` (legacy: `HELO quarantine`), never STARTTLS (legacy
 * sends in clear on mailcow's internal network), the configured host, port and timeout
 * (`restv2.release_smtp`). The message's bare LF line endings are sent as CRLF; the transport
 * dot-stuffs lines that start with `.` (legacy does it by hand). The connection is closed with
 * `QUIT` after each message.
 */
class SmtpQuarantineReleaseMailer implements QuarantineReleaseMailer
{
    public function deliver(RawMessage $message, string $sender, string $recipient): DeliveryOutcome
    {
        $transport = new EsmtpTransport((string) config('restv2.release_smtp.host', 'postfix'), (int) config('restv2.release_smtp.port', 590), false);
        $transport->setAutoTls(false);
        $transport->setLocalDomain('quarantine');
        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) max(1, (int) config('restv2.release_smtp.timeout', 30)));
        }

        try {
            $transport->send(new RawMessage(self::crlf($message->toString())), new Envelope(new Address($sender), [new Address($recipient)]));
        } catch (TransportExceptionInterface $e) {
            return self::classify($e);
        } catch (Throwable $e) {
            return DeliveryOutcome::temporaryFailure($e->getMessage());
        } finally {
            try {
                $transport->stop();
            } catch (Throwable) {
                // The connection is gone already; the outcome above stands.
            }
        }

        return DeliveryOutcome::accepted();
    }

    /** Bare LF (and CR) line endings as CRLF. */
    public static function crlf(string $message): string
    {
        return (string) preg_replace('/\r\n|\r|\n/', "\r\n", $message);
    }

    /**
     * `5xx` → refused with the server's full reply; `4xx`, connection failures and timeouts →
     * temporary failure.
     */
    private static function classify(TransportExceptionInterface $e): DeliveryOutcome
    {
        $code = (int) $e->getCode();
        $message = $e->getMessage();
        $reply = preg_match('/with message "(.*)"\.?$/s', $message, $m) === 1 ? trim($m[1]) : $message;

        if ($code >= 500 && $code <= 599) {
            return DeliveryOutcome::refused($code, $reply);
        }

        return DeliveryOutcome::temporaryFailure($code >= 400 && $code <= 499 ? $reply : $message);
    }
}
