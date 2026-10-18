<?php

namespace App\Services\Webmail;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * A webmail sign-in handed to one browser (feature 005 FR-067, FR-068): the mailbox, the address
 * to open, and the legacy session cookie that the response must set. The legacy session ID is a
 * secret of that browser; it is never logged or audited.
 */
final readonly class WebmailHandOff
{
    public function __construct(
        public string $mailbox,
        public string $url,
        public Cookie $cookie,
    ) {}

    /**
     * @return array{mailbox: string, url: string}
     */
    public function toArray(): array
    {
        return ['mailbox' => $this->mailbox, 'url' => $this->url];
    }
}
