<?php

namespace App\Services\AliasDomains;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Services\Aliases\AliasLimit;
use App\Services\Audit\AuditLogger;
use App\Services\Domains\DomainPresenter;
use App\Services\Groupware\SogoDirectory;
use App\Services\RateLimits\RateLimitStore;
use App\Support\Domain\DomainName;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use Illuminate\Support\Facades\DB;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;

/**
 * Alias domain changes, legacy mailbox('edit', 'alias_domain') (feature 004 FR-044, research R12):
 * the target and the active state, without a permission flag. In one transaction on the locked row:
 * the version check (`412`), the target rules of creation and the read-only members (`422`), the
 * alias limit of a new target for the aliases that move with the alias domain (`409`), legacy's
 * `UPDATE` and the audit record. `DOMAIN_MAP` is not touched: legacy keeps the entry while the alias
 * domain exists, and Postfix checks `active` itself. The groupware directory is rebuilt after the
 * commit, unless nothing changed or the bulk caller defers it.
 */
class AliasDomainUpdater
{
    /** Read-only members of `AliasDomainPatch`; `etag` is ignored. */
    public const READ_ONLY = ['name', 'nameUnicode', 'targetDomainUnicode', 'targetIsRelayDomain', 'aliasCount', 'rateLimit', 'createdAt', 'modifiedAt'];

    public function __construct(
        private readonly AliasDomainRules $rules,
        private readonly AliasDomainVersion $version,
        private readonly AliasLimit $limit,
        private readonly RateLimitStore $rateLimits,
        private readonly SogoDirectory $sogo,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $patch  the sent members of `AliasDomainPatch`
     * @return bool whether anything changed
     */
    public function update(Principal $principal, string $name, array $patch, ?string $ifMatch, ?string $bulkRequestId = null, bool $deferRebuild = false): bool
    {
        $changed = DB::transaction(function () use ($principal, $name, $patch, $ifMatch, $bulkRequestId): bool {
            $row = DB::table('alias_domain')->where('alias_domain', $name)->lockForUpdate()->first();

            if (! $row instanceof stdClass) {
                throw new NotFoundHttpException('The alias domain does not exist.');
            }

            if (ETag::preconditionFails($ifMatch, $this->version->forRow($row))) {
                throw new PreconditionFailedHttpException('The alias domain was changed since it was read.');
            }

            $current = ['targetDomain' => (string) $row->target_domain, 'active' => (int) $row->active !== 0];
            $values = $current;
            $violations = new FieldViolations;

            if (array_key_exists('targetDomain', $patch)) {
                $sent = is_string($patch['targetDomain']) ? DomainName::normalizeValid($patch['targetDomain']) : null;
                $values['targetDomain'] = $sent === $current['targetDomain'] ? $sent : ($this->rules->target($principal, $patch['targetDomain'], $violations) ?? $current['targetDomain']);
            }

            if (array_key_exists('active', $patch)) {
                $values['active'] = (bool) $patch['active'];
            }

            $this->immutables($patch, $row, $values['targetDomain'], $violations);
            $violations->throwIfAny();

            if ($values['targetDomain'] !== $current['targetDomain']) {
                $this->limit->lockPrimaryDomain($values['targetDomain']);
                $this->limit->assertRoom($values['targetDomain'], DB::table('alias')->where('domain', $name)->count());
            }

            $changes = [];

            foreach ($current as $field => $old) {
                if ($values[$field] !== $old) {
                    $changes[$field] = ['old' => $old, 'new' => $values[$field]];
                }
            }

            if ($changes !== []) {
                DB::table('alias_domain')->where('alias_domain', $name)->update([
                    'target_domain' => $values['targetDomain'],
                    'active' => isset($changes['active']) ? (int) $values['active'] : (int) $row->active,
                ]);
            }

            $details = ['changes' => $changes === [] ? new stdClass : $changes];

            if ($bulkRequestId !== null) {
                $details['bulkRequestId'] = $bulkRequestId;
            }

            $this->audit->record(AuditEventType::AliasDomainUpdate, true, $principal, ['type' => 'aliasDomain', 'id' => $name, 'name' => DomainName::toUnicode($name)], null, null, $details);

            return $changes !== [];
        }, 3);

        if ($changed && ! $deferRebuild) {
            $this->sogo->rebuild();
        }

        return $changed;
    }

    /**
     * Read-only members are accepted when equal to the current value, or, for those that follow
     * from the target, to the value of the resulting target; otherwise `immutable`.
     *
     * @param  array<string, mixed>  $patch
     */
    private function immutables(array $patch, object $row, string $target, FieldViolations $violations): void
    {
        $sent = array_intersect_key($patch, array_flip(self::READ_ONLY));

        if ($sent === []) {
            return;
        }

        $name = (string) $row->alias_domain;
        $current = [
            'name' => $name,
            'nameUnicode' => DomainName::toUnicode($name),
            'targetDomainUnicode' => DomainName::toUnicode($target),
            'targetIsRelayDomain' => (int) DB::table('domain')->where('domain', $target)->value('backupmx') === 1,
            'aliasCount' => DB::table('alias')->where('domain', $name)->count(),
            'createdAt' => DomainPresenter::time($row->created),
            'modifiedAt' => DomainPresenter::time($row->modified),
        ];

        foreach ($sent as $member => $value) {
            $expected = $member === 'rateLimit' ? $this->rateLimits->get($name) : $current[$member];
            $equal = $member === 'rateLimit'
                ? (is_array($value) && is_array($expected) ? (int) ($value['value'] ?? 0) === $expected['value'] && ($value['frame'] ?? null) === $expected['frame'] : $value === $expected)
                : $value === $expected;

            if (! $equal) {
                $violations->add($member, 'immutable', [], 'This member is read-only and differs from the current value.');
            }
        }
    }
}
