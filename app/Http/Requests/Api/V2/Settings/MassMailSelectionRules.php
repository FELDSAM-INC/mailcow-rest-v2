<?php

namespace App\Http\Requests\Api\V2\Settings;

use App\Services\MassMail\Selection;
use App\Services\MassMail\SelectionResolver;
use App\Services\Settings\SettingsLimits;
use Illuminate\Contracts\Validation\Validator;

/**
 * The `selection` member of the mass mail requests (`MassMailSelection`, feature 013 FR-073): three
 * optional lists of at most 1,000 entries each; once their types are valid, SelectionResolver names
 * every entry that is not part of the receiving mailboxes, reported together with the other fields.
 */
trait MassMailSelectionRules
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function selectionRules(): array
    {
        $list = ['bail', 'sometimes', 'array', 'list', 'max:'.SettingsLimits::MASS_MAIL_SELECTION_ENTRIES_MAX];
        $entry = ['bail', 'string', 'max:255'];

        return [
            'selection' => ['bail', 'present', 'array'],
            'selection.domains' => $list,
            'selection.domains.*' => $entry,
            'selection.include' => $list,
            'selection.include.*' => $entry,
            'selection.exclude' => $list,
            'selection.exclude.*' => $entry,
        ];
    }

    /**
     * @return array<string, list<array{code: string, params: array<string, mixed>, message: string}>>
     */
    protected function selectionViolations(Validator $validator): array
    {
        $selection = $this->json('selection');
        $violations = [];

        if (is_array($selection) && ! array_is_list($selection)) {
            foreach (array_keys($selection) as $member) {
                if (! in_array($member, ['domains', 'include', 'exclude'], true)) {
                    $violations["selection.{$member}"][] = self::make('unknownField', [], 'This member is not accepted here.');
                }
            }
        }

        foreach (array_keys($validator->errors()->messages()) as $field) {
            if (str_starts_with((string) $field, 'selection')) {
                return $violations;
            }
        }

        if (! is_array($selection)) {
            return $violations;
        }

        return $violations + app(SelectionResolver::class)->violations($this->selection())->all();
    }

    public function selection(): Selection
    {
        $selection = $this->json('selection');

        return Selection::fromInput(is_array($selection) ? $selection : []);
    }
}
