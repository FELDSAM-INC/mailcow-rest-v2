<?php

namespace App\Services\Quarantine;

/**
 * The outcome of each step of a release or a learning as spam (contract `QuarantineActionReport`,
 * FR-025): delivery `done`/`notApplicable`; learning `done`, `alreadyLearned`, `failed`,
 * `notRequested`; fuzzy hashes `done`, `skipped`, `failed`, `notRequested`.
 */
final readonly class ActionReport
{
    public function __construct(
        public int $itemId,
        public string $queueId,
        public string $action,
        public string $delivery,
        public string $learning,
        public string $fuzzy,
        public ?string $learningError = null,
        public ?string $fuzzyError = null,
    ) {}

    /**
     * @return array{delivery: string, learning: string, fuzzy: string}
     */
    public function steps(): array
    {
        return ['delivery' => $this->delivery, 'learning' => $this->learning, 'fuzzy' => $this->fuzzy];
    }

    /**
     * @return array{itemId: int, queueId: string, action: string, delivery: string, learning: string, fuzzy: string, itemRemoved: true, learningError: string|null, fuzzyError: string|null}
     */
    public function toArray(): array
    {
        return [
            'itemId' => $this->itemId,
            'queueId' => $this->queueId,
            'action' => $this->action,
            'delivery' => $this->delivery,
            'learning' => $this->learning,
            'fuzzy' => $this->fuzzy,
            'itemRemoved' => true,
            'learningError' => $this->learningError,
            'fuzzyError' => $this->fuzzyError,
        ];
    }
}
