<?php

namespace App\Services\Domains;

/**
 * The outcome of each follow-up step of a domain creation (FR-025, contract
 * `DomainCreationReport`), with an English message per failed step. The same report is stored in
 * the creation's audit record.
 */
final class DomainCreationReport
{
    public string $rateLimit = 'notRequested';

    public string $dkimKey = 'notRequested';

    public string $dkimDnsPublication = 'skipped';

    public string $groupwareDirectoryUpdate = 'groupwareDisabled';

    public string $groupwareRestart = 'notRequested';

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
            'groupwareRestart' => $this->groupwareRestart,
        ];

        if ($this->messages !== []) {
            $report['messages'] = $this->messages;
        }

        return $report;
    }
}
