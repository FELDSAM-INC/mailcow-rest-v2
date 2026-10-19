<?php

namespace App\Services\MassMail;

use Carbon\CarbonImmutable;
use Symfony\Component\Mime\Email;

/**
 * The message of one mass mail recipient (feature 013 FR-076, research R18), built with
 * `symfony/mime` as legacy's PHPMailer builds it: `From` the sender, `To` only the recipient,
 * `Subject` (UTF-8 encoded when needed), `Date`, `Message-ID: <job.recipient@MAILCOW_HOSTNAME>` and
 * legacy's `X-Mailer: MooMassMail`; `text/plain`, or `multipart/alternative` with both bodies when
 * an HTML body is given.
 */
class MassMailMessageFactory
{
    public function build(string $jobId, int $recipientId, string $from, string $to, string $subject, string $text, ?string $html, CarbonImmutable $date): string
    {
        $email = (new Email)
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->date($date)
            ->text($text, 'utf-8');

        if ($html !== null && $html !== '') {
            $email->html($html, 'utf-8');
        }

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $jobId.'.'.$recipientId.'@'.strtolower((string) config('restv2.mailcow_hostname')));
        $headers->addTextHeader('X-Mailer', 'MooMassMail');

        return $email->toString();
    }
}
