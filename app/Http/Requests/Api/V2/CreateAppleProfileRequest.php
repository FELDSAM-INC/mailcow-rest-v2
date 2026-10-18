<?php

namespace App\Http\Requests\Api\V2;

use App\Auth\Role;
use App\Exceptions\ProblemException;
use App\Http\Controllers\Api\V2\OwnAppleProfileController;
use App\Http\RequestContext;
use App\Support\Http\ProblemType;

/**
 * Body of `createOwnAppleProfileWithAppPassword` (`AppleProfileRequest`, feature 005 FR-082),
 * optional: the variant and the app password's name.
 */
class CreateAppleProfileRequest extends ApiFormRequest
{
    /** Mailbox users only (`403 forbidden` before the body is checked). */
    public function authorize(): bool
    {
        if (RequestContext::requirePrincipal($this)->role !== Role::MailboxUser) {
            throw new ProblemException(ProblemType::Forbidden, 'Only mailbox users download a connection profile.');
        }

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'variant' => ['bail', 'sometimes', 'string', 'in:'.implode(',', OwnAppleProfileController::VARIANTS)],
            'appPasswordName' => ['bail', 'sometimes', 'string', 'min:1', 'max:255'],
        ];
    }

    protected function memberViolations(array $body): array
    {
        return StoreSieveFilterRequest::unknownMembersOf($body, ['variant', 'appPasswordName']);
    }

    public function variant(): string
    {
        $variant = $this->input('variant');

        return is_string($variant) ? $variant : 'emailAndDav';
    }

    public function appPasswordName(): ?string
    {
        $name = $this->input('appPasswordName');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
