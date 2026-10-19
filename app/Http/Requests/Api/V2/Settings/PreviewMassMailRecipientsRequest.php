<?php

namespace App\Http\Requests\Api\V2\Settings;

use Illuminate\Contracts\Validation\Validator;

/**
 * Body of `previewMassMailRecipients` (`MassMailPreviewRequest`, feature 013 FR-072, FR-073): the
 * selection; paging and the address search are query parameters. Administrators only, read-only
 * keys included.
 */
class PreviewMassMailRecipientsRequest extends SettingsRequest
{
    use MassMailSelectionRules;

    protected const MEMBERS = ['selection'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return $this->selectionRules();
    }

    protected function additionalViolations(Validator $validator): array
    {
        return $this->selectionViolations($validator);
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->json()->all();
    }
}
