<?php

namespace App\Services\Settings;

use App\Services\Audit\RefusalAuditor;
use App\Services\Config\MailcowConfig;
use App\Services\Domains\DomainAuditTargets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Target resolvers of feature 013 for the shared refusal auditing (research R24): one per target
 * type of the system settings. The identifier comes from the route: the logo `variant`, the
 * settings rule `ruleId` (named by its description when it exists), the regex map `mapId` (named
 * by its configured English name), the Sieve `filterId`, the copy recipients' `domain` (normalised
 * as feature 002 names domains, cut to 255 characters) and the mass mail `jobId` (named by its
 * subject). Singleton settings have no identifier. The audit log is for administrators, so names
 * are looked up regardless of the caller's scope.
 *
 * It also maps this feature's problem types to their failure reasons: `demo-mode` → `demoMode`,
 * `feature-disabled` → `featureDisabled`, the two unavailable files → `fileUnavailable` and the two
 * mass mail conflicts → `conflict`.
 */
final class SettingsAuditTargetResolver
{
    public const SINGLETONS = [
        'uiTexts', 'loginPage', 'appLinks', 'brandColors', 'ipCheck', 'passwordPolicy', 'passwordResetNotification',
        'quotaNotification', 'rspamdUiPassword', 'legacyApiCors', 'license',
    ];

    public const OBJECTS = ['logo', 'quotaNotificationRecipients', 'rspamdSettingsRule', 'rspamdRegexMap', 'globalSieveFilter', 'massMailJob'];

    public static function register(RefusalAuditor $auditor): void
    {
        foreach ([...self::SINGLETONS, ...self::OBJECTS] as $type) {
            $auditor->resolveTargetsWith($type, fn (Request $request): array => ['target' => self::target($type, $request)]);
        }

        $auditor->mapProblemType('/api/v2/problems/demo-mode', 'demoMode');
        $auditor->mapProblemType('/api/v2/problems/feature-disabled', 'featureDisabled');
        $auditor->mapProblemType('/api/v2/problems/regex-map-unavailable', 'fileUnavailable');
        $auditor->mapProblemType('/api/v2/problems/global-sieve-filter-unavailable', 'fileUnavailable');
        $auditor->mapProblemType('/api/v2/problems/mass-mail-already-running', 'conflict');
        $auditor->mapProblemType('/api/v2/problems/mass-mail-recipient-count-changed', 'conflict');
    }

    /**
     * @return array{type: string, id: string|null, name: string|null}
     */
    public static function target(string $type, Request $request): array
    {
        $param = fn (string $name): ?string => is_scalar($request->route($name)) && (string) $request->route($name) !== '' ? mb_substr((string) $request->route($name), 0, 255) : null;

        return match ($type) {
            'logo' => ['type' => $type, 'id' => $param('variant'), 'name' => null],
            'globalSieveFilter' => ['type' => $type, 'id' => $param('filterId'), 'name' => null],
            'quotaNotificationRecipients' => DomainAuditTargets::named($type, $request->route('domain')) ?? ['type' => $type, 'id' => null, 'name' => null],
            'rspamdSettingsRule' => ['type' => $type, 'id' => $id = $param('ruleId'), 'name' => self::lookup(fn () => $id !== null && ctype_digit($id) ? DB::table('settingsmap')->where('id', (int) $id)->value('desc') : null)],
            'rspamdRegexMap' => ['type' => $type, 'id' => $map = $param('mapId'), 'name' => self::lookup(fn () => $map === null ? null : array_search($map, app(MailcowConfig::class)->rspamdRegexMaps(), true))],
            'massMailJob' => ['type' => $type, 'id' => $job = $param('jobId'), 'name' => self::lookup(fn () => $job === null ? null : DB::table('restv2_mass_mail_jobs')->where('id', $job)->value('subject'))],
            default => ['type' => $type, 'id' => null, 'name' => null],
        };
    }

    /**
     * @param  callable(): mixed  $lookup
     */
    private static function lookup(callable $lookup): ?string
    {
        try {
            $name = $lookup();
        } catch (Throwable) {
            return null;
        }

        return is_string($name) && $name !== '' ? mb_substr($name, 0, 255) : null;
    }
}
