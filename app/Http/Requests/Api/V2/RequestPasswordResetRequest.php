<?php

namespace App\Http\Requests\Api\V2;

use App\Rules\EmailAddress;
use App\Services\Mailboxes\MailboxAddress;
use App\Services\PasswordReset\PasswordResetThrottle;

/**
 * Body of `requestPasswordReset` (`PasswordResetRequest`, feature 005 FR-019): a mailbox address,
 * any letter case, the domain in ASCII or Unicode form. Every attempt counts towards the client
 * address's limit before anything else is checked (FR-026).
 */
class RequestPasswordResetRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        app(PasswordResetThrottle::class)->attempt('request', (string) $this->ip());

        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return ['address' => ['bail', 'required', 'string', 'max:255', new EmailAddress]];
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $address = $this->input('address');

        // The domain may be given in its Unicode form; legacy compares the ASCII form.
        if (is_string($address)) {
            $normalised = MailboxAddress::normalize($address);
            $this->merge(['address' => $normalised === null ? $address : $normalised->address]);
        }
    }

    public function address(): string
    {
        return (string) $this->input('address');
    }
}
