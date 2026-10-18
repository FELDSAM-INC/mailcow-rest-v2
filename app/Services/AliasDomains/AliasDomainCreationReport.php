<?php

namespace App\Services\AliasDomains;

/**
 * The outcome of each follow-up step of an alias domain creation (feature 004 FR-043, contract
 * `AliasDomainCreationReport`), with an English message per failed step. The same report is stored
 * in the creation's audit record.
 */
final class AliasDomainCreationReport
{
    public string $rateLimit = 'notRequested';

    public string $dkimKey = 'notRequested';

    public string $dkimDnsPublication = 'skipped';

    public string $groupwareDirectoryUpdate = 'done';

    /** @var array<string, string> step => message */
    private array $messages = [];

    public function fail(string $step, string $message): void
    {
        $this->{$step} = 'failed';
        $this->messages[$step] = $message;
    }

    public function message(string $step, string $message): void
    {
        $this->messages[$step] = $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $report = [
            'rateLimit' => $this->rateLimit,
            'dkimKey' => $this->dkimKey,
            'dkimDnsPublication' => $this->dkimDnsPublication,
            'groupwareDirectoryUpdate' => $this->groupwareDirectoryUpdate,
        ];

        if ($this->messages !== []) {
            $report['messages'] = $this->messages;
        }

        return $report;
    }
}
