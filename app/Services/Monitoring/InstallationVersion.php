<?php

namespace App\Services\Monitoring;

/**
 * The installed mailcow version (contract `InstallationVersion`, feature 011 FR-018): tag, branch,
 * commit, commit date in UTC (RFC 3339) and project address; null when mailcow does not record it.
 */
final readonly class InstallationVersion
{
    public function __construct(
        public ?string $tag,
        public ?string $branch,
        public ?string $commit,
        public ?string $commitDate,
        public ?string $projectUrl,
    ) {}

    /**
     * @return array{tag: string|null, branch: string|null, commit: string|null, commitDate: string|null, projectUrl: string|null}
     */
    public function toArray(): array
    {
        return [
            'tag' => $this->tag,
            'branch' => $this->branch,
            'commit' => $this->commit,
            'commitDate' => $this->commitDate,
            'projectUrl' => $this->projectUrl,
        ];
    }
}
