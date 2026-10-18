<?php

namespace App\Services\AliasDomains;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\SharedStore\SharedStore;
use App\Services\Audit\AuditLogger;
use App\Services\Groupware\SogoDirectory;
use App\Support\Domain\DomainName;
use App\Support\Http\ETag;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * Alias domain deletion, legacy mailbox('delete', 'alias_domain') with the deviations of FR-045
 * (feature 004 research R11):
 *
 *  1. refuse with `503 temporary-failure` when mailcow's shared store is down;
 *  2. in one transaction on the locked row: the version check (`412`), 002's shared
 *     AliasDomainCleanup (its aliases and their sender permissions, the permissions for the alias
 *     domain, temporary aliases, BCC maps in both forms, spam lists, the row), the name removed from
 *     the target's footer exclusions (only here: a domain deletion removes the whole footer), the
 *     `DOMAIN_MAP` and `RL_VALUE` fields removed (restored if the transaction fails), the audit
 *     record with what else was removed;
 *  3. after the commit, the groupware directory rebuild, unless the bulk caller defers it.
 *
 * The DKIM key and the external sender addresses that name the alias domain are kept.
 */
class AliasDomainDeleter
{
    public function __construct(
        private readonly SharedStore $store,
        private readonly AliasDomainVersion $version,
        private readonly AliasDomainCleanup $cleanup,
        private readonly SogoDirectory $sogo,
        private readonly AuditLogger $audit,
    ) {}

    public function delete(Principal $principal, string $name, ?string $ifMatch, ?string $bulkRequestId = null, bool $deferRebuild = false): void
    {
        $this->store->ping();
        $undo = null;

        try {
            DB::transaction(function () use ($principal, $name, $ifMatch, $bulkRequestId, &$undo): void {
                $undo = null;
                $row = DB::table('alias_domain')->where('alias_domain', $name)->lockForUpdate()->first();

                if (! $row instanceof stdClass) {
                    throw new NotFoundHttpException('The alias domain does not exist.');
                }

                if (ETag::preconditionFails($ifMatch, $this->version->forRow($row))) {
                    throw new PreconditionFailedHttpException('The alias domain was changed since it was read.');
                }

                $removed = $this->removedCounts($name);
                $this->cleanup->deleteRows($name);
                $removed['footerExclusion'] = $this->removeFooterExclusion((string) $row->target_domain, $name);
                $undo = $this->store->deleteFields($this->cleanup->redisFields($name));

                $details = ['removed' => $removed];

                if ($bulkRequestId !== null) {
                    $details['bulkRequestId'] = $bulkRequestId;
                }

                $this->audit->record(AuditEventType::AliasDomainDeletion, true, $principal, ['type' => 'aliasDomain', 'id' => $name, 'name' => DomainName::toUnicode($name)], null, null, $details);
            }, 3);
        } catch (Throwable $e) {
            $this->undo($undo, $name);

            throw $e;
        }

        if (! $deferRebuild) {
            $this->sogo->rebuild();
        }
    }

    /**
     * What the shared cleanup is about to remove, for the audit record.
     *
     * @return array<string, mixed>
     */
    private function removedCounts(string $name): array
    {
        $aliases = DB::table('alias')->where('domain', $name)->orderBy('id')->get(['id', 'address'])
            ->map(fn (object $a): array => ['id' => (int) $a->id, 'address' => (string) $a->address])->all();

        return [
            'aliases' => $aliases,
            'senderPermissions' => DB::table('sender_acl')
                ->where(fn ($q) => $q->whereIn('send_as', DB::table('alias')->select('address')->where('domain', $name))->orWhere('send_as', '@'.$name))
                ->count(),
            'temporaryAliases' => DB::table('spamalias')->where('address', 'like', DomainName::addressPattern($name))->count(),
            'bccMaps' => DB::table('bcc_maps')->whereIn('local_dest', [$name, '@'.$name])->count(),
            'spamFilterSettings' => DB::table('filterconf')->where('object', $name)->count(),
        ];
    }

    /**
     * Removes the alias domain from its target domain's footer exclusions; true when it was there.
     */
    private function removeFooterExclusion(string $target, string $name): bool
    {
        $footer = DB::table('domain_wide_footer')->where('domain', $target)->lockForUpdate()->first(['alias_domain_exclude']);
        $excluded = $footer === null ? null : json_decode((string) $footer->alias_domain_exclude, true);

        if (! is_array($excluded)) {
            return false;
        }

        $kept = array_values(array_filter($excluded, fn (mixed $d): bool => ! is_string($d) || mb_strtolower($d) !== mb_strtolower($name)));

        if (count($kept) === count($excluded)) {
            return false;
        }

        DB::table('domain_wide_footer')->where('domain', $target)->update(['alias_domain_exclude' => json_encode($kept)]);

        return true;
    }

    private function undo(?Closure $undo, string $name): void
    {
        if ($undo === null) {
            return;
        }

        try {
            $undo();
        } catch (Throwable $e) {
            Log::error("The shared-store entries of the alias domain {$name} could not be restored after the failed deletion: ".$e->getMessage());
        }
    }
}
