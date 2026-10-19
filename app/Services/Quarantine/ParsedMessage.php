<?php

namespace App\Services\Quarantine;

/**
 * The content parsed from a quarantined message (contract `QuarantineMessageContent`, feature 006
 * research R5).
 */
final readonly class ParsedMessage
{
    /**
     * @param  list<array{address: string, source: string}>  $recipients
     * @param  list<ParsedAttachment>  $attachments
     */
    public function __construct(
        public string $from,
        public string $subject,
        public array $recipients,
        public ?string $textPlain,
        public string $textFromHtml,
        public bool $parserFallback,
        public array $attachments,
    ) {}

    public function attachment(int $position): ?ParsedAttachment
    {
        return $this->attachments[$position] ?? null;
    }

    /**
     * The contract representation; attachments only for callers who may download them.
     *
     * @return array{from: string, subject: string, recipients: list<array{address: string, source: string}>, textPlain: string|null, textFromHtml: string, parserFallback: bool, attachments: list<array<string, mixed>>|null}
     */
    public function toArray(bool $withAttachments): array
    {
        return [
            'from' => $this->from,
            'subject' => $this->subject,
            'recipients' => $this->recipients,
            'textPlain' => $this->textPlain,
            'textFromHtml' => $this->textFromHtml,
            'parserFallback' => $this->parserFallback,
            'attachments' => $withAttachments ? array_map(fn (ParsedAttachment $a): array => $a->toArray(), $this->attachments) : null,
        ];
    }
}
