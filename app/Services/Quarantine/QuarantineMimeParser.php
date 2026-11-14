<?php

namespace App\Services\Quarantine;

use App\Exceptions\ProblemException;
use App\Support\Http\ProblemType;
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
    public const MAX_PARTS = 256;

    /**
     * Bound the entire MIME tree before the parent creates part objects or attachment streams.
     * mailparse's own MAXPARTS limit applies to siblings, so nested messages can exceed it.
     *
     * @param  string  $data
     * @return $this
     */
    public function setText($data)
    {
        if (! str_ends_with($data, "\n")) {
            $data .= "\n";
        }

        $this->resource = mailparse_msg_create();
        $length = strlen($data);
        for ($offset = 0; $offset < $length; $offset += 8192) {
            $valid = @mailparse_msg_parse($this->resource, substr($data, $offset, 8192));
            $parts = mailparse_msg_get_structure($this->resource);
            if (! $valid || count($parts) > self::MAX_PARTS) {
                throw new ProblemException(ProblemType::QuarantineMessageTooComplex);
            }
        }

        $this->data = $data;
        $this->parse();

        return $this;
    }

    /**
     * The declared (decoded) file name of each attachment of getAttachments(true), or null for an
     * unnamed one.
     *
     * @return list<string|null>
     */
    public function attachmentNames(): array
    {
        return array_values($this->attachmentNamesByPart());
    }

    /** Select body text using the same attachment classification as attachment downloads. */
    public function bodyWithoutAttachments(string $type): string
    {
        $attachmentIds = array_keys($this->attachmentNamesByPart());
        foreach ($this->getParts() as $id => $part) {
            if ($this->getPart('content-type', $part) !== ($type === 'html' ? 'text/html' : 'text/plain')) {
                continue;
            }
            foreach ($attachmentIds as $attachmentId) {
                if ((string) $id === (string) $attachmentId || $this->partIdIsChildOfPart($id, $attachmentId)) {
                    continue 2;
                }
            }
            $headers = $this->getPart('headers', $part);
            $body = $this->decodeContentTransfer($this->getPartBody($part), $headers['content-transfer-encoding'] ?? '');

            return $this->charset->decodeCharset($body, $this->getPartCharset($part));
        }

        return '';
    }

    /** @return array<int|string, string|null> */
    private function attachmentNamesByPart(): array
    {
        $names = [];

        foreach ($this->getParts() as $id => $part) {
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
                $names[$id] = $name;
            }
        }

        return $names;
    }
}
