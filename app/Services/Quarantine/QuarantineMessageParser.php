<?php

namespace App\Services\Quarantine;

use Soundasleep\Html2Text;
use Throwable;

/**
 * Parses a quarantined message as legacy inc/ajax/qitem_details.php does (feature 006 research R5,
 * FR-013), with legacy's libraries (php-mime-mail-parser on PECL mailparse, soundasleep/html2text):
 *
 *  - recipients from the To, Cc and Bcc headers (valid addresses only, as legacy filters them with
 *    FILTER_VALIDATE_EMAIL), then the envelope recipient (`envelope`);
 *  - the From header and the subject (`mb_convert_encoding(..., 'UTF-8', 'auto')`), empty strings
 *    instead of legacy's `-`;
 *  - the plain-text part (`null` when empty) and the HTML part converted to text; when neither
 *    exists, the raw body after the first blank line converted to text, with `parserFallback`
 *    (legacy "Parser failed, assuming HTML");
 *  - every text scrubbed of invalid UTF-8 (legacy blanks it), so that JSON never breaks;
 *  - the attachments of getAttachments(true) (inline parts included), in order, with the declared
 *    name made safe (`attachment-<position>` when unnamed), the declared type, the decoded size and
 *    SHA-256, and the decoded content in memory (legacy writes the files to /tmp).
 *
 * The caller parses only messages within the content size limit (FR-014).
 */
class QuarantineMessageParser
{
    public function parse(string $message, string $recipient): ParsedMessage
    {
        $parser = new QuarantineMimeParser;
        $parser->setText($message);

        $recipients = [];

        foreach (['to', 'cc', 'bcc'] as $header) {
            foreach (self::addresses($parser, $header) as $address) {
                $recipients[] = ['address' => self::text($address), 'source' => $header];
            }
        }

        $recipients[] = ['address' => $recipient, 'source' => 'envelope'];

        $plain = self::text(self::body($parser, 'text'));
        $fromHtml = self::text(self::html2text(self::body($parser, 'html')));
        $fallback = false;

        if ($plain === '' && $fromHtml === '') {
            $fallback = true;
            $fromHtml = self::text(self::html2text(self::rawBody($message)));
        }

        $subject = $parser->getHeader('subject');
        $from = $parser->getHeader('from');

        return new ParsedMessage(
            from: is_string($from) ? self::text($from) : '',
            subject: is_string($subject) ? self::text((string) mb_convert_encoding($subject, 'UTF-8', 'auto')) : '',
            recipients: $recipients,
            textPlain: $fallback || $plain === '' ? null : $plain,
            textFromHtml: $fromHtml,
            parserFallback: $fallback,
            attachments: self::attachments($parser),
        );
    }

    /**
     * @return list<string>
     */
    private static function addresses(QuarantineMimeParser $parser, string $header): array
    {
        try {
            $addresses = $parser->getAddresses($header);
        } catch (Throwable) {
            return [];
        }

        $valid = [];

        foreach ($addresses as $address) {
            if (filter_var($address['address'], FILTER_VALIDATE_EMAIL) !== false) {
                $valid[] = (string) $address['address'];
            }
        }

        return $valid;
    }

    private static function body(QuarantineMimeParser $parser, string $type): string
    {
        try {
            return (string) $parser->getMessageBody($type);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Legacy's fallback: the raw message after the first blank line (`\r\n\r\n`, or `\n\n` for a
     * message with LF line endings), trimmed.
     */
    private static function rawBody(string $message): string
    {
        foreach (["\r\n\r\n", "\n\n"] as $separator) {
            $at = strpos($message, $separator);

            if ($at !== false) {
                return trim(substr($message, $at + strlen($separator)));
            }
        }

        return trim($message);
    }

    private static function html2text(string $html): string
    {
        if ($html === '') {
            return '';
        }

        try {
            return Html2Text::convert($html, ['ignore_errors' => true]);
        } catch (Throwable) {
            return trim(strip_tags($html));
        }
    }

    /**
     * @return list<ParsedAttachment>
     */
    private static function attachments(QuarantineMimeParser $parser): array
    {
        $names = $parser->attachmentNames();
        $attachments = [];

        foreach ($parser->getAttachments(true) as $position => $attachment) {
            $content = (string) $attachment->getContent();
            $attachments[] = new ParsedAttachment(
                position: $position,
                fileName: DownloadFileName::forAttachment(self::text($names[$position] ?? ''), $position),
                contentType: self::text((string) $attachment->getContentType()),
                size: strlen($content),
                sha256: hash('sha256', $content),
                content: $content,
            );
        }

        return $attachments;
    }

    private static function text(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }
}
