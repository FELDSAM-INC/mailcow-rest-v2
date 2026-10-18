<?php

namespace App\Services\SyncJobs;

use App\Auth\Principal;
use App\Auth\Role;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Services\Config\MailcowConfig;

/**
 * Custom imapsync parameters (feature 008 FR-006, FR-019, research R7), checked as legacy's
 * mailbox('add', 'syncjob') checks them: the trimmed value is split at every `-`; pieces that are
 * empty or `0` are skipped; a piece's option name is the text before its first `=`, or the piece
 * without trailing spaces; a name containing a space is refused (`optionContainsSpace`), and a name
 * whose lower case is not on the allow-list is refused (`optionNotAllowed`). Unlike legacy, every
 * bad option is reported (once, in order), and `showpasswords` is never on the allow-list, so that
 * the remote password can never be written into the run log.
 *
 * When the server restricts custom parameters to administrators, any other role's value that
 * differs from the stored one (at creation: any non-empty value) is refused with
 * `customParamsNotPermitted` alone. A value sent unchanged on an edit is always accepted, even when
 * it predates the current allow-list.
 */
class CustomParametersValidator
{
    public function __construct(private readonly MailcowConfig $config) {}

    public function restrictedToAdministrators(): bool
    {
        return config('restv2.sync_jobs.custom_params_admin_only') === true;
    }

    /** Whether the caller may set custom parameters (FR-006). */
    public function allowedFor(Principal $principal): bool
    {
        return ! $this->restrictedToAdministrators() || $principal->role === Role::Admin;
    }

    /**
     * The allow-list in effect: the configured whitelist, in its order, without `showpasswords`
     * (compared case-insensitively).
     *
     * @return list<string>
     */
    public function allowedOptions(): array
    {
        return array_values(array_filter(
            $this->config->imapsyncOptions()['whitelist'],
            fn (string $option): bool => strtolower($option) !== 'showpasswords',
        ));
    }

    /**
     * Every violation of a sent value; [] when it is accepted.
     *
     * @return list<array{code: string, params: array<string, mixed>, message: string}>
     */
    public function violations(string $value, ?string $stored, Principal $caller, bool $creating): array
    {
        $trimmed = trim($value);
        $unchanged = ! $creating && $stored !== null && ($value === $stored || $trimmed === $stored);

        if (! $this->allowedFor($caller) && ($creating ? $trimmed !== '' : ! $unchanged)) {
            return [ApiFormRequest::make('customParamsNotPermitted', [], 'Only administrators may set custom imapsync parameters on this server.')];
        }

        return $unchanged ? [] : $this->check($trimmed);
    }

    /**
     * Legacy's loop over the pieces, collecting every violation.
     *
     * @return list<array{code: string, params: array<string, mixed>, message: string}>
     */
    private function check(string $value): array
    {
        $allowed = $this->allowedOptions();
        $violations = [];

        foreach (explode('-', $value) as $piece) {
            if ($piece === '' || $piece === '0') {
                continue;
            }

            $name = str_contains($piece, '=') ? explode('=', $piece)[0] : rtrim($piece, ' ');

            if (str_contains($name, ' ')) {
                $violations['space:'.$name] = ApiFormRequest::make('optionContainsSpace', ['token' => $name], "The imapsync option \"{$name}\" contains a space; write --option=value.");
            } elseif (! in_array(strtolower($name), $allowed, true)) {
                $violations['option:'.$name] = ApiFormRequest::make('optionNotAllowed', ['option' => $name], "The imapsync option \"{$name}\" is not allowed.");
            }
        }

        return array_values($violations);
    }
}
