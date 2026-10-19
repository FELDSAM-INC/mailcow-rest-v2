<?php

namespace App\Services\Quarantine;

use PhpMimeMailParser\Parser;

/**
 * Legacy's MIME parser (php-mime-mail-parser, on PECL mailparse) with the attachment names as the
 * message declares them (feature 006 research R5): getAttachments() names an unnamed part
 * `noname<n>` and escapes the declared name, so the API takes the declared name of each attachment
 * instead and makes it safe itself (DownloadFileName, FR-016). attachmentNames() selects the parts
 * exactly as getAttachments(true) does, in the same order.
 */
class QuarantineMimeParser extends Parser
{
    /**
     * The declared (decoded) file name of each attachment of getAttachments(true), or null for an
     * unnamed one.
     *
     * @return list<string|null>
     */
    public function attachmentNames(): array
    {
        $names = [];

        foreach ($this->getParts() as $part) {
            $disposition = $this->getPart('content-disposition', $part);
            $name = null;

            if (isset($part['disposition-filename'])) {
                $name = (string) $this->decodeHeader($part['disposition-filename']);
            } elseif (isset($part['content-name'])) {
                $name = (string) $this->decodeHeader($part['content-name']);
                $disposition = 'attachment';
            } elseif (in_array($part['content-type'], ['text/plain', 'text/html'], true) && $disposition !== 'attachment') {
                continue;
            } elseif (substr((string) $part['content-type'], 0, 10) !== 'multipart/'
                && $part['content-type'] !== 'text/plain; (error)' && $disposition != 'inline') {
                $disposition = 'attachment';
            }

            if (! in_array($disposition, ['attachment', 'inline'], true) && ! empty($disposition)) {
                $disposition = 'attachment';
            }

            if (in_array($disposition, ['attachment', 'inline'], true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
