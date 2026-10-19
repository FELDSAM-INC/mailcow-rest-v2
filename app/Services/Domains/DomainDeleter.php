<?php

namespace App\Services\Domains;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\DockerApi\DockerApiClient;
use App\Clients\SharedStore\SharedStore;
use App\Exceptions\ProblemException;
use App\Services\AliasDomains\AliasDomainCleanup;
use App\Services\Audit\AuditLogger;
use App\Services\DomainAdmins\OrphanedDomainAdminRemover;
use App\Services\Groupware\SogoDirectory;
use App\Support\Domain\DomainName;
use App\Support\Http\ETag;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * Domain deletion, legacy mailbox('delete', 'domain') with the deviations of FR-037 (research D17):
 *
 *  1. refuse with `503 temporary-failure` when mailcow's shared store is down;
 *  2. in one transaction: lock the row, check the version (`412`), lock the domain's mailboxes and
 *     refuse when any mailbox or resource remains (`409 domain-not-empty`); delete the rows legacy
 *     deletes, plus the BCC maps in both forms, the footer and every alias domain as its own
 *     deletion would (AliasDomainCleanup); delete the domain administrators left without a domain;
 *     remove the `DOMAIN_MAP` and `RL_VALUE` fields of the domain and its alias domains (restored if
 *     the transaction fails); write the audit record with the stored-mail outcome "pending";
 *  3. after the commit, ask dovecot to move the stored mail to the garbage area (a failure never
 *     undoes the deletion) and complete the audit record; then rebuild the groupware directory,
 *     unless the bulk caller defers it.
 *
 * The DKIM key is kept (FR-038).
 */
class DomainDeleter
{
    public function __construct(
        private readonly SharedStore $store,
        private readonly DomainVersion $version,
        private readonly DomainDeletionPlanner $planner,
        private readonly AliasDomainCleanup $aliasDomains,
        private readonly OrphanedDomainAdminRemover $orphans,
        private readonly DockerApiClient $docker,
        private readonly SogoDirectory $sogo,
        private readonly AuditLogger $audit,
    ) {}

    public function delete(Principal $principal, string $name, ?string $ifMatch, ?string $bulkRequestId = null, bool $deferRebuild = false): void
    {
        $this->store->ping();
        $undo = null;

        try {
            $eventId = DB::transaction(function () use ($principal, $name, $ifMatch, $bulkRequestId, &$undo): string {
                $undo = null;
                $row = DB::table('domain')->where('domain', $name)->lockForUpdate()->first();

                if (! $row instanceof stdClass) {
                    throw new NotFoundHttpException('The domain does not exist.');
                }

                $tags = DB::table('tags_domain')->where('domain', $name)->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all();

                if (ETag::preconditionFails($ifMatch, $this->version->forDomain($row, $tags))) {
                    throw new PreconditionFailedHttpException('The domain was changed since it was read.');
                }

                $kinds = DB::table('mailbox')->where('domain', $name)->lockForUpdate()->pluck('kind')->map(fn (mixed $k): string => (string) $k);
                $mailboxes = $kinds->filter(fn (string $k): bool => $k === '')->count();
                $resources = $kinds->count() - $mailboxes;

                if ($kinds->isNotEmpty()) {
                    throw new ProblemException(ProblemType::DomainNotEmpty, "{$name} still has {$mailboxes} mailboxes and {$resources} resources.", ['mailboxes' => $mailboxes, 'resources' => $resources]);
                }

                $aliasDomains = $this->planner->aliasDomains($name);
                $footer = DB::table('domain_wide_footer')->where('domain', $name)->exists();
                $this->deleteRows($name, $aliasDomains);
                $removedAdmins = $this->orphans->remove($principal, $name);

                $fields = ['DOMAIN_MAP' => [$name], 'RL_VALUE' => [$name]];

                foreach ($aliasDomains as $aliasDomain) {
                    $fields = array_merge_recursive($fields, $this->aliasDomains->redisFields($aliasDomain));
                }

                $undo = $this->store->deleteFields($fields);

                $details = [
                    'removedAliasDomains' => $aliasDomains,
                    'removedDomainAdmins' => $removedAdmins,
                    'footerRemoved' => $footer,
                    'maildirCleanup' => 'pending',
                ];

                if ($bulkRequestId !== null) {
                    $details['bulkRequestId'] = $bulkRequestId;
                }

                return $this->audit->record(AuditEventType::DomainDeletion, true, $principal, ['type' => 'domain', 'id' => $name, 'name' => DomainName::toUnicode($name)], null, null, $details)->id;
            }, 3);
        } catch (Throwable $e) {
            $this->undo($undo, $name);

            throw $e;
        }

        $this->cleanupMaildir($eventId, $name);

        if (! $deferRebuild) {
            $this->sogo->rebuild();
        }
    }

    /**
     * @param  list<string>  $aliasDomains
     */
    private function deleteRows(string $name, array $aliasDomains): void
    {
        $addresses = DomainName::addressPattern($name);

        // Legacy's statements (the tags go with the row through their foreign key).
        DB::table('domain')->where('domain', $name)->delete();
        DB::table('domain_admins')->where('domain', $name)->delete();
        DB::table('alias')->where('domain', $name)->delete();
        DB::table('sender_acl')->where('logged_in_as', 'like', $addresses)->delete();
        DB::table('quota2')->where('username', 'like', $addresses)->delete();
        DB::table('pushover')->where('username', 'like', $addresses)->delete();
        DB::table('quota2replica')->where('username', 'like', $addresses)->delete();
        DB::table('spamalias')->where('address', 'like', $addresses)->delete();
        DB::table('filterconf')->where('object', $name)->delete();
        DB::table('mta_sts')->where('domain', $name)->delete();

        // Unlike legacy (FR-037): BCC maps in both forms, the footer, the alias domains.
        DB::table('bcc_maps')->whereIn('local_dest', [$name, '@'.$name])->delete();
        DB::table('domain_wide_footer')->where('domain', $name)->delete();

        foreach ($aliasDomains as $aliasDomain) {
            $this->aliasDomains->deleteRows($aliasDomain);
        }

        DB::table('alias_domain')->where('target_domain', $name)->delete();
    }

    private function cleanupMaildir(string $eventId, string $name): void
    {
        $result = $this->docker->exec('dovecot-mailcow', ['cmd' => 'maildir', 'task' => 'cleanup', 'maildir' => $name]);
        $outcome = match (true) {
            $result->isSuccess() => ['outcome' => 'done'],
            $result->isUnknown() => ['outcome' => 'unknown', 'message' => 'The container service did not answer in time; the stored mail may or may not have been moved.'],
            default => ['outcome' => 'failed', 'message' => (string) $result->message],
        };

        if ($outcome['outcome'] !== 'done') {
            Log::warning("The stored mail of the deleted domain {$name} was not moved to the garbage area: ".$outcome['message']);
        }

        try {
            $this->audit->completeFollowUps($eventId, 'maildirCleanup', $outcome);
        } catch (Throwable $e) {
            Log::error("The stored-mail outcome of {$name} could not be added to its audit record: ".$e->getMessage());
        }
    }

    private function undo(?Closure $undo, string $name): void
    {
        if ($undo === null) {
            return;
        }

        try {
            $undo();
        } catch (Throwable $e) {
            Log::error("The shared-store entries of {$name} could not be restored after the failed deletion: ".$e->getMessage());
        }
    }
}
