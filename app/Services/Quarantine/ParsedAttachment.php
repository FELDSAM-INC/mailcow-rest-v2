<?php

namespace App\Services\Quarantine;

/**
 * One attachment of a parsed quarantined message (contract `QuarantineAttachment`), with its decoded
 * content kept in memory for the download (nothing is written to disk).
 */
final readonly class ParsedAttachment
{
    public function __construct(
        public int $position,
        public string $fileName,
        public string $contentType,
        public int $size,
        public string $sha256,
        public string $content,
    ) {}

    /**
     * @return array{position: int, fileName: string, contentType: string, size: int, sha256: string}
     */
    public function toArray(): array
    {
        return ['position' => $this->position, 'fileName' => $this->fileName, 'contentType' => $this->contentType, 'size' => $this->size, 'sha256' => $this->sha256];
    }
}
