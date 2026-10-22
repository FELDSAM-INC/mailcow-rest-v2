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
 * bad option is reported (once, in order). Execution and password-disclosure options are always
 * denied, and actual runner argument boundaries are checked before the legacy allow-list.
 *
 * When the server restricts custom parameters to administrators, any other role's value that
 * differs from the stored one (at creation: any non-empty value) is refused with
 * `customParamsNotPermitted` alone. Safe values sent unchanged on an edit are accepted even when
 * they predate the current allow-list; unsafe stored arguments must first be removed.
 */
class CustomParametersValidator
{
    /** Never pass execution or credential-disclosure options through, even from older jobs. */
    private const UNSAFE_OPTIONS = [
        'showpasswords', 'skipmess', 'delete2foldersonly', 'delete2foldersbutnot',
        'regexflag', 'regexmess', 'pipemess', 'regextrans2', 'maxlinelengthcmd',
    ];

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
     * The configured whitelist, in order, without immutable or configured denied options.
     *
     * @return list<string>
     */
    public function allowedOptions(): array
    {
        return array_values(array_filter(
            $this->config->imapsyncOptions()['whitelist'],
            fn (string $option): bool => ! in_array(strtolower($option), $this->deniedOptions(), true),
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

        // The runner and Getopt::Long recognise argument boundaries differently from legacy's
        // dash-splitting loop. Inspect the actual argv shape before granting the unchanged-value
        // exception; unsafe stored options must not be grandfathered in.
        $unsafe = $this->safetyViolations($trimmed);

        $violations = $unchanged ? [] : $this->check($trimmed);
        foreach ($unsafe as $violation) {
            if (! in_array($violation, $violations, true)) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /** @return list<string> */
    private function deniedOptions(): array
    {
        return array_values(array_unique([
            ...self::UNSAFE_OPTIONS,
            ...array_map('strtolower', $this->config->imapsyncOptions()['blacklist']),
        ]));
    }

    /**
     * Match imapsync_runner.pl's qqw: split before --, then once at a literal space, and trim.
     * Only dash-prefixed options are part of this API's grammar. In particular Getopt::Long's
     * alternate plus prefix must never become an unvalidated second option inside a value.
     *
     * @return list<array{code: string, params: array<string, mixed>, message: string}>
     */
    public function safetyViolations(string $value): array
    {
        $violations = [];
        $denied = $this->deniedOptions();
        foreach (preg_split('/(?=--)/', trim($value)) ?: [] as $piece) {
            foreach (explode(' ', $piece, 2) as $argument) {
                $argument = trim($argument);
                if (preg_match('/^[-+]+([^=\s]*)/', $argument, $matches) !== 1) {
                    continue;
                }
                $name = $matches[1];
                // Getopt::Long also accepts unambiguous abbreviated names. Do not grandfather
                // abbreviations of denied options that may have been saved by older versions.
                $blocked = $name !== '' && array_filter($denied, fn (string $option): bool => str_starts_with($option, strtolower($name))) !== [];
                if (str_starts_with($argument, '+') || $blocked) {
                    $violations[$name] = ApiFormRequest::make('optionNotAllowed', ['option' => $name], "The imapsync option \"{$name}\" is not allowed.");
                }
            }
        }

        return array_values($violations);
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
