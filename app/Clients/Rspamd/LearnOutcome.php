<?php

namespace App\Clients\Rspamd;

/**
 * The outcome of a learning or fuzzy call (feature 006 research R2): its LearnResult and, for a
 * failure, the controller's answer or the cURL error as text.
 */
final readonly class LearnOutcome
{
    public function __construct(public LearnResult $result, public ?string $error = null) {}

    public static function done(): self
    {
        return new self(LearnResult::Done);
    }

    public static function alreadyLearned(): self
    {
        return new self(LearnResult::AlreadyLearned);
    }

    public static function noFuzzyContent(): self
    {
        return new self(LearnResult::NoFuzzyContent);
    }

    public static function failed(string $error): self
    {
        return new self(LearnResult::Failed, $error);
    }

    /** Learned now or before: both count as success, as in legacy. */
    public function succeeded(): bool
    {
        return $this->result === LearnResult::Done || $this->result === LearnResult::AlreadyLearned;
    }
}
