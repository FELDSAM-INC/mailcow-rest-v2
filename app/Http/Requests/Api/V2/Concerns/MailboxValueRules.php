<?php

namespace App\Http\Requests\Api\V2\Concerns;

use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Rules\StrictBoolean;
use App\Services\Mailboxes\MailboxValues;
use App\Services\Mailboxes\UserPermissions;
use Illuminate\Validation\Rule;

/**
 * The contract types and bounds of the values mailboxes and mailbox templates share (feature 003),
 * and the refusal of members a request schema does not define (`immutable`). Business rules (whole
 * MiB, quota limits, tags, password policy) are the services', so that they are reported together.
 */
trait MailboxValueRules
{
    /** The largest quota in bytes (JSON's largest safe integer, as the contract's int64). */
    public const MAX_QUOTA = 9007199254740991;

    /**
     * @param  list<string>  $members  the value members the request accepts
     * @return array<string, list<mixed>>
     */
    protected function valueRules(array $members): array
    {
        $flag = ['bail', 'sometimes', new StrictBoolean];
        $rateLimit = fn (): bool => is_array($this->input('rateLimit'));
        $rules = [
            'quota' => ['bail', 'sometimes', 'integer', 'between:0,'.self::MAX_QUOTA],
            'status' => ['bail', 'sometimes', 'string', 'in:'.implode(',', array_keys(MailboxValues::STATUSES))],
            'forcePasswordChange' => $flag,
            'forceSecondFactorSetup' => $flag,
            'webmailRedirect' => $flag,
            'protocolAccess' => ['bail', 'sometimes', 'array', 'min:1'],
            'userPermissions' => ['bail', 'sometimes', 'array', 'min:1'],
            'tags' => ['bail', 'sometimes', 'array', 'list'],
            'tags.*' => ['bail', 'string'],
            'taggedMailHandling' => ['bail', 'sometimes', 'string', 'in:'.implode(',', MailboxValues::TAGGED_MAIL_HANDLING)],
            'quarantineNotification' => ['bail', 'sometimes', 'string', 'in:'.implode(',', MailboxValues::QUARANTINE_NOTIFICATIONS)],
            'quarantineCategory' => ['bail', 'sometimes', 'string', 'in:'.implode(',', array_keys(MailboxValues::QUARANTINE_CATEGORIES))],
            'tlsEnforceInbound' => $flag,
            'tlsEnforceOutbound' => $flag,
            'rateLimit' => ['bail', 'sometimes', 'nullable', 'array'],
            'rateLimit.value' => ['bail', Rule::requiredIf($rateLimit), 'integer', 'between:1,2147483647'],
            'rateLimit.frame' => ['bail', Rule::requiredIf($rateLimit), 'string', 'in:second,minute,hour,day'],
        ];

        foreach (array_keys(MailboxValues::PROTOCOLS) as $protocol) {
            $rules['protocolAccess.'.$protocol] = $flag;
        }

        foreach (array_keys(UserPermissions::COLUMNS) as $permission) {
            $rules['userPermissions.'.$permission] = $flag;
        }

        return array_filter($rules, function (string $field) use ($members): bool {
            return in_array(explode('.', $field)[0], $members, true);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * `immutable` for every member outside the accepted ones, also inside `protocolAccess`,
     * `userPermissions` and `rateLimit`.
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $members
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    protected function unknownMembers(array $body, array $members): array
    {
        $violations = [];
        $nested = [
            'protocolAccess' => array_keys(MailboxValues::PROTOCOLS),
            'userPermissions' => array_keys(UserPermissions::COLUMNS),
            'rateLimit' => ['value', 'frame'],
        ];

        foreach ($body as $member => $value) {
            if (! in_array((string) $member, $members, true)) {
                $violations[(string) $member] = ApiFormRequest::make('immutable', [], 'This member cannot be set here.');

                continue;
            }

            if (isset($nested[$member]) && is_array($value) && ! array_is_list($value)) {
                foreach (array_keys($value) as $key) {
                    if (! in_array((string) $key, $nested[$member], true)) {
                        $violations[$member.'.'.$key] = ApiFormRequest::make('immutable', [], 'This member cannot be set here.');
                    }
                }
            }
        }

        return $violations;
    }

    /**
     * The members that were sent (merge semantics).
     *
     * @return array<string, mixed>
     */
    public function members(): array
    {
        $body = json_decode((string) $this->getContent(), true);

        return is_array($body) ? $body : [];
    }
}
