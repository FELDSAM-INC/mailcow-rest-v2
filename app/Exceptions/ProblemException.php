<?php

namespace App\Exceptions;

use App\Support\Http\ProblemType;
use RuntimeException;

/**
 * Ends a request with a documented problem type. Rendered by App\Exceptions\ProblemRenderer and
 * never reported as an error. Audit details, when given, go into the refusal's audit record only.
 */
class ProblemException extends RuntimeException implements CarriesAuditDetails
{
    /**
     * @param  array<string, mixed>  $extensions  extension members (for example `retryAfter`)
     * @param  array<string, string>  $headers  response headers (for example `Retry-After`)
     * @param  array<string, mixed>  $auditDetails  further details of the failure record
     */
    public function __construct(
        public readonly ProblemType $type,
        public readonly ?string $detail = null,
        public readonly array $extensions = [],
        public readonly array $headers = [],
        public readonly array $auditDetails = [],
    ) {
        parent::__construct($detail ?? $type->title());
    }

    public function auditDetails(): array
    {
        return $this->auditDetails;
    }

    /**
     * `403 attribute-not-permitted` naming each refused member and, for feature 006's notification
     * settings, the flag each one needs (`requiredFlags`, member => `PermissionFlags` name).
     *
     * @param  list<string>  $attributes
     * @param  array<string, string>  $requiredFlags
     */
    public static function attributeNotPermitted(array $attributes, array $requiredFlags = []): self
    {
        return new self(
            ProblemType::AttributeNotPermitted,
            'Your permission flags do not allow changing: '.implode(', ', $attributes).'.',
            ['attributes' => $attributes] + ($requiredFlags === [] ? [] : ['requiredFlags' => $requiredFlags]),
            [],
            $requiredFlags === [] ? [] : ['requiredFlags' => array_values(array_unique($requiredFlags))],
        );
    }

    /** `409 quarantine-item-busy` (feature 006 FR-026): another request processes the item. */
    public static function quarantineItemBusy(): self
    {
        return new self(ProblemType::QuarantineItemBusy, null, [], ['Retry-After' => '5']);
    }

    /** `409 quarantine-delivery-refused` (feature 006 FR-021) with the mail server's answer. */
    public static function quarantineDeliveryRefused(int $code, string $reply): self
    {
        return new self(ProblemType::QuarantineDeliveryRefused, null, ['smtpCode' => $code, 'smtpResponse' => $reply]);
    }

    /** `409 quarantine-message-too-large` (feature 006 FR-014), sizes in bytes. */
    public static function quarantineMessageTooLarge(int $size, int $limit): self
    {
        return new self(ProblemType::QuarantineMessageTooLarge, "The stored message has {$size} bytes, more than the content size limit of {$limit} bytes.", ['size' => $size, 'limit' => $limit]);
    }

    /** `409 spam-policy-entry-exists` (feature 006 FR-047): the list and ID of the existing entry. */
    public static function spamPolicyEntryExists(string $list, int $entryId): self
    {
        return new self(ProblemType::SpamPolicyEntryExists, "The pattern is already on the {$list} list of this owner (entry {$entryId}).", ['list' => $list, 'entryId' => $entryId]);
    }

    /**
     * `503 temporary-failure` with feature 006's extension members: the unavailable `dependency`
     * (`sharedStore`, `spamFilter`, `mailServer`) and, for item actions, the failed `step`
     * (`delivery`, `learning`). Retry after 30 seconds.
     */
    public static function temporaryFailure(string $dependency, ?string $step = null, ?string $detail = null): self
    {
        return new self(
            ProblemType::TemporaryFailure,
            $detail ?? match ($dependency) {
                'mailServer' => 'The mail server could not be reached or answered temporarily. The item was kept.',
                'spamFilter' => 'The spam filter could not be reached or refused the request. Nothing was changed.',
                default => null,
            },
            ['dependency' => $dependency] + ($step === null ? [] : ['step' => $step]),
            ['Retry-After' => '30'],
        );
    }
}
