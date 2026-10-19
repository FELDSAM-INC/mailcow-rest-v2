<?php

namespace App\Services\SpamPolicy;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\Rspamd\RspamdController;
use App\Policies\SpamPolicyPolicy;
use App\Services\Access\SpamFilterScope;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ETag;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * A mailbox's spam score (feature 006 research R10, FR-039 to FR-042): the `filterconf` rows
 * `lowspamlevel` (junk) and `highspamlevel` (reject) that Rspamd's settings map reads, with the spam
 * filter's "add header" and "reject" thresholds (`GET /actions`) as defaults, or 5 and 15 marked as
 * fallback when the filter cannot be asked or a threshold is missing or 0 (legacy `empty()`).
 *
 * A change locks the mailbox row, compares `If-Match`, deletes both rows and inserts
 * `highspamlevel` then `lowspamlevel` (legacy's order) with the values as the shortest decimals;
 * equal scores raise the reject score by 0.1 (integer tenths, no float artefacts). A reset deletes
 * both rows and is audited only when custom values existed.
 */
class SpamScoreService
{
    public const FALLBACK_JUNK = 5;

    public const FALLBACK_REJECT = 15;

    private const OPTIONS = ['lowspamlevel', 'highspamlevel'];

    public function __construct(
        private readonly SpamFilterScope $scope,
        private readonly SpamPolicyPolicy $policy,
        private readonly RspamdController $rspamd,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function read(Principal $principal, string $rawMailbox): array
    {
        $this->policy->authorize($principal, SpamPolicyPolicy::VIEW_SCORE);
        $mailbox = $this->scope->mailbox($principal, $rawMailbox);

        return $this->present($mailbox, $this->rows($mailbox));
    }

    /**
     * @return array<string, mixed>
     */
    public function set(Principal $principal, string $rawMailbox, float|int $junk, float|int $reject, ?string $ifMatch): array
    {
        $this->policy->authorize($principal, SpamPolicyPolicy::CHANGE_SCORE);
        $mailbox = $this->scope->mailbox($principal, $rawMailbox);
        $junkTenths = self::tenths($junk);
        $rejectTenths = self::tenths($reject);

        if ($junkTenths === $rejectTenths) {
            $rejectTenths++;
        }

        DB::transaction(function () use ($principal, $mailbox, $junkTenths, $rejectTenths, $ifMatch): void {
            $rows = $this->lockedRows($mailbox);
            $this->checkVersion($mailbox, $rows, $ifMatch);
            DB::table('filterconf')->where('object', $mailbox)->whereIn('option', self::OPTIONS)->delete();
            DB::table('filterconf')->insert(['object' => $mailbox, 'option' => 'highspamlevel', 'value' => self::decimal($rejectTenths)]);
            DB::table('filterconf')->insert(['object' => $mailbox, 'option' => 'lowspamlevel', 'value' => self::decimal($junkTenths)]);

            $this->audit->record(AuditEventType::SpamScoreUpdate, true, $principal, self::target($mailbox), null, null, ['changes' => [
                'junkScore' => ['old' => self::number($rows['lowspamlevel'] ?? null), 'new' => self::fromTenths($junkTenths)],
                'rejectScore' => ['old' => self::number($rows['highspamlevel'] ?? null), 'new' => self::fromTenths($rejectTenths)],
            ]]);
        });

        return $this->present($mailbox, $this->rows($mailbox));
    }

    public function reset(Principal $principal, string $rawMailbox, ?string $ifMatch): void
    {
        $this->policy->authorize($principal, SpamPolicyPolicy::CHANGE_SCORE);
        $mailbox = $this->scope->mailbox($principal, $rawMailbox);

        DB::transaction(function () use ($principal, $mailbox, $ifMatch): void {
            $rows = $this->lockedRows($mailbox);
            $this->checkVersion($mailbox, $rows, $ifMatch);

            if ($rows === []) {
                return;
            }

            DB::table('filterconf')->where('object', $mailbox)->whereIn('option', self::OPTIONS)->delete();
            $changes = [];

            foreach (['junkScore' => 'lowspamlevel', 'rejectScore' => 'highspamlevel'] as $member => $option) {
                if (array_key_exists($option, $rows)) {
                    $changes[$member] = ['old' => self::number($rows[$option]), 'new' => null];
                }
            }

            $this->audit->record(AuditEventType::SpamScoreReset, true, $principal, self::target($mailbox), null, null, ['changes' => $changes]);
        });
    }

    /**
     * @return array{type: string, id: string, name: string}
     */
    private static function target(string $mailbox): array
    {
        return ['type' => 'spamScore', 'id' => $mailbox, 'name' => $mailbox];
    }

    /**
     * @param  array<string, string>  $rows
     */
    private function checkVersion(string $mailbox, array $rows, ?string $ifMatch): void
    {
        if (ETag::preconditionFails($ifMatch, self::etag($mailbox, $rows))) {
            throw new PreconditionFailedHttpException('The spam score has changed since it was read.');
        }
    }

    /**
     * @return array<string, string> option => value (the first row of each option)
     */
    private function rows(string $mailbox): array
    {
        $rows = [];

        foreach (DB::table('filterconf')->where('object', $mailbox)->whereIn('option', self::OPTIONS)->orderBy('prefid')->get(['option', 'value']) as $row) {
            $rows[(string) $row->option] ??= (string) $row->value;
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function lockedRows(string $mailbox): array
    {
        DB::table('mailbox')->where('username', $mailbox)->lockForUpdate()->value('username');

        return $this->rows($mailbox);
    }

    /**
     * @param  array<string, string>  $rows
     * @return array<string, mixed>
     */
    private function present(string $mailbox, array $rows): array
    {
        $defaults = $this->defaults();

        return [
            'mailbox' => $mailbox,
            'junkScore' => array_key_exists('lowspamlevel', $rows) ? self::number($rows['lowspamlevel']) ?? $defaults['junkScore'] : $defaults['junkScore'],
            'rejectScore' => array_key_exists('highspamlevel', $rows) ? self::number($rows['highspamlevel']) ?? $defaults['rejectScore'] : $defaults['rejectScore'],
            'custom' => $rows !== [],
            'defaults' => $defaults,
            'etag' => self::etag($mailbox, $rows),
        ];
    }

    /**
     * The spam filter's thresholds, or the fallback (legacy mailbox('get', 'spam_score')).
     *
     * @return array{junkScore: int|float, rejectScore: int|float, fallback: bool}
     */
    private function defaults(): array
    {
        try {
            $actions = $this->rspamd->actions();
        } catch (Throwable) {
            $actions = [];
        }

        $values = [];

        foreach ($actions as $action) {
            if (is_array($action) && is_string($action['action'] ?? null)) {
                $values[$action['action']] = $action['value'] ?? null;
            }
        }

        $junk = self::number($values['add header'] ?? null);
        $reject = self::number($values['reject'] ?? null);

        if (empty($junk) || empty($reject)) {
            return ['junkScore' => self::FALLBACK_JUNK, 'rejectScore' => self::FALLBACK_REJECT, 'fallback' => true];
        }

        return ['junkScore' => $junk, 'rejectScore' => $reject, 'fallback' => false];
    }

    /**
     * @param  array<string, string>  $rows
     */
    private static function etag(string $mailbox, array $rows): string
    {
        return ETag::of(['spamScore', $mailbox, $rows['lowspamlevel'] ?? null, $rows['highspamlevel'] ?? null]);
    }

    private static function tenths(float|int $value): int
    {
        return (int) round($value * 10);
    }

    private static function fromTenths(int $tenths): int|float
    {
        return $tenths % 10 === 0 ? intdiv($tenths, 10) : $tenths / 10;
    }

    /** The shortest decimal of a value in tenths (`12`, `6.5`, `0.4`). */
    private static function decimal(int $tenths): string
    {
        return $tenths % 10 === 0 ? (string) intdiv($tenths, 10) : sprintf('%s%d.%d', $tenths < 0 ? '-' : '', intdiv(abs($tenths), 10), abs($tenths) % 10);
    }

    /** A stored or reported number, whole numbers as integers; null when not numeric. */
    private static function number(mixed $value): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        $float = (float) $value;

        return floor($float) === $float && abs($float) < PHP_INT_MAX ? (int) $float : round($float, 2);
    }
}
