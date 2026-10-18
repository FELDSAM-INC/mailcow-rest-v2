<?php

namespace App\Services\SelfService;

use App\Audit\AuditEventType;
use App\Http\RequestContext;
use App\Services\Audit\RefusalAuditor;
use App\Services\Mailboxes\MailboxAddress;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Target resolvers and failure reasons of feature 005 for the shared refusal auditing (research
 * R22):
 *
 *  - mailbox objects (`appPassword`, `temporaryAlias`, `sieveFilter`, `pushNotificationSettings`,
 *    `mailSettings`, `recentLogins`, `groupwareData`): `id` is the path's mailbox address,
 *    normalised when possible (so that `targetId=<mailbox>` lists every record of the mailbox),
 *    `name` the object's label when the path names one; a refused bulk request is recorded once
 *    with a null target, `targetIds` and `bulkAction`;
 *  - own-account targets (`accountPassword`, `secondFactor`, `recoveryEmail`, `webmailHandOff`):
 *    the signed-in account.
 *
 * The feature's own problem types map to their failure reasons. The audit log is for
 * administrators, so addresses outside the caller's scope may be recorded.
 */
final class SelfServiceAuditTargets
{
    private const MAILBOX_TARGETS = ['appPassword', 'temporaryAlias', 'sieveFilter', 'pushNotificationSettings', 'mailSettings', 'recentLogins', 'groupwareData'];

    private const OWN_TARGETS = ['accountPassword', 'secondFactor', 'recoveryEmail', 'webmailHandOff'];

    /** Failure reasons of feature 005's problem types (contract `AuditFailureReason`). */
    private const REASONS = [
        ProblemType::CurrentPasswordIncorrect->value => 'currentPasswordIncorrect',
        ProblemType::PasswordManagedExternally->value => 'externalIdentitySource',
        ProblemType::PasswordResetUnavailable->value => 'featureDisabled',
        ProblemType::InvalidResetToken->value => 'invalidResetToken',
        ProblemType::SecondFactorEnrolmentExpired->value => 'challengeExpired',
        ProblemType::SecondFactorEnrolmentFailed->value => 'registrationFailed',
        ProblemType::LastSecondFactor->value => 'lastSecondFactor',
        ProblemType::SecondFactorRequiredByPolicy->value => 'secondFactorRequiredByPolicy',
        ProblemType::PushNotificationsNotConfigured->value => 'notConfigured',
        ProblemType::GroupwareDisabled->value => 'featureDisabled',
        ProblemType::TemporaryVerificationFailure->value => 'verificationUnavailable',
        ProblemType::SecondFactorFailed->value => 'secondFactorFailed',
        ProblemType::SecondFactorUnavailable->value => 'secondFactorUnavailable',
        ProblemType::TooManyRequests->value => 'throttled',
    ];

    public static function register(RefusalAuditor $auditor): void
    {
        foreach (self::MAILBOX_TARGETS as $type) {
            $auditor->resolveTargetsWith($type, fn (Request $request, array $config): array => self::mailboxObject($type, $request, $config));
        }

        foreach (self::OWN_TARGETS as $type) {
            $auditor->resolveTargetsWith($type, fn (Request $request): array => self::ownAccount($type, $request));
        }

        foreach (self::REASONS as $problemType => $reason) {
            $auditor->mapProblemType('/api/v2/problems/'.$problemType, $reason);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{target?: array{type: string, id: string|null, name: string|null}|null, details?: array<string, mixed>, event?: AuditEventType}
     */
    public static function mailboxObject(string $type, Request $request, array $config): array
    {
        if (($config['event'] ?? null) === 'fromBulkAction') {
            $targets = $request->input('targets');
            $ids = [];

            foreach (is_array($targets) ? array_slice($targets, 0, 100) : [] as $target) {
                if (is_array($target) && is_string($target['id'] ?? null)) {
                    $ids[] = mb_substr($target['id'], 0, 255);
                }
            }

            $action = $request->input('action');

            return [
                'event' => AuditEventType::MailSettingsUpdate,
                'target' => null,
                'details' => ['targetIds' => $ids, 'bulkAction' => is_string($action) ? mb_substr($action, 0, 32) : null],
            ];
        }

        $raw = $request->route('mailbox');
        $mailbox = is_string($raw) && $raw !== '' ? self::address($raw) : null;

        return ['target' => ['type' => $type, 'id' => $mailbox, 'name' => self::label($type, $request, $mailbox)]];
    }

    /**
     * @return array{target: array{type: string, id: string|null, name: string|null}}
     */
    public static function ownAccount(string $type, Request $request): array
    {
        $principal = RequestContext::principal($request);

        return ['target' => ['type' => $type, 'id' => $principal?->username, 'name' => null]];
    }

    /**
     * A mailbox address as sent, normalised when it can be, cut to 255 characters.
     */
    public static function address(string $raw): string
    {
        return mb_substr(MailboxAddress::normalize($raw)->address ?? trim($raw), 0, 255);
    }

    private static function label(string $type, Request $request, ?string $mailbox): ?string
    {
        $value = match ($type) {
            'temporaryAlias' => $request->route('temporaryAlias'),
            'appPassword' => self::lookup('app_passwd', 'mailbox', 'name', $mailbox, $request->route('appPasswordId'), true),
            'sieveFilter' => self::lookup('sieve_filters', 'username', 'script_desc', $mailbox, $request->route('sieveFilterId'), false),
            default => null,
        };

        return is_string($value) && $value !== '' ? mb_substr($value, 0, 255) : null;
    }

    private static function lookup(string $table, string $owner, string $column, ?string $mailbox, mixed $id, bool $encoded): ?string
    {
        if ($mailbox === null || ! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        $value = DB::table($table)->where('id', (int) $id)->where($owner, $mailbox)->value($column);

        return is_string($value) ? ($encoded ? htmlspecialchars_decode($value, ENT_QUOTES) : $value) : null;
    }
}
