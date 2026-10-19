<?php

namespace App\Services\Quarantine;

/**
 * The outcome of a notification template check or preview (feature 006 research R7): the
 * renderings (`html` and the notifier's plain-text `text`) of each dataset, or the reason
 * (`syntax`, `security`, `render`, `timeout`), the template line when known and the message.
 */
final readonly class TemplateCheckResult
{
    /**
     * @param  list<array{html: string, text: string}>  $renders
     */
    public function __construct(
        public bool $ok,
        public array $renders = [],
        public ?string $reason = null,
        public ?int $line = null,
        public ?string $message = null,
    ) {}

    public static function failed(string $reason, ?int $line, ?string $message): self
    {
        return new self(false, [], $reason, $line, $message);
    }
}
