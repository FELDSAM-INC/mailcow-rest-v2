<?php

namespace App\Services\Dkim;

/**
 * Outcome of the DKIM follow-up step of a domain or alias domain creation (feature 010 research
 * R10): `created`, `alreadyExisted` or `failed`, with the DNS publication of a created key.
 */
final readonly class DkimCreationStep
{
    public const CREATED = 'created';

    public const ALREADY_EXISTED = 'alreadyExisted';

    public const FAILED = 'failed';

    public function __construct(
        public string $outcome,
        public ?DkimDnsPublicationResult $publication = null,
        public ?string $message = null,
    ) {}
}
