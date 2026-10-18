<?php

namespace App\Services\Mailboxes;

/**
 * The outcome of each follow-up step of a mailbox creation (contract `MailboxCreationReport`,
 * feature 003 FR-027), returned in the response and stored in the creation's audit record.
 */
final class MailboxCreationReport
{
    public string $rateLimit = 'notRequested';

    public string $taggedMailHandling = 'notRequested';

    public string $groupwareDirectoryUpdate = 'notRequired';

    /** @var array<string, string> */
    private array $messages = [];

    public function fail(string $step, string $message): void
    {
        $this->{$step} = 'failed';
        $this->messages[$step] = $message;
    }

    /**
     * @return array{rateLimit: string, taggedMailHandling: string, groupwareDirectoryUpdate: string, messages?: array<string, string>}
     */
    public function toArray(): array
    {
        $report = [
            'rateLimit' => $this->rateLimit,
            'taggedMailHandling' => $this->taggedMailHandling,
            'groupwareDirectoryUpdate' => $this->groupwareDirectoryUpdate,
        ];

        return $this->messages === [] ? $report : $report + ['messages' => $this->messages];
    }
}
