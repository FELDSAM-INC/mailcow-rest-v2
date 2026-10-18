<?php

namespace App\Services\Mailboxes;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\DockerApi\DockerCallResult;
use App\Clients\Memcached\MemcachedFlusher;
use App\Clients\SharedStore\SharedStore;
use App\Exceptions\AuditedFailure;
use App\Exceptions\MailboxDependencyUnavailable;
use App\Exceptions\OutcomeUnknown;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditLogger;
use App\Services\Domains\DomainUsageCalculator;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Redis;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * Mailbox rename, legacy mailbox('edit', 'mailbox_rename') as a compensated sequence of steps
 * (feature 003 FR-043–FR-048, research R17). Under the address locks of the old and the new
 * address:
 *
 *  - preflight, nothing changed yet: the new local part (`422`, also `unchanged`), the version
 *    (`412`), the status (`409 mailbox-not-renamable`), the new address free (`409 address-in-use`),
 *    room for the kept alias (`409 domain-alias-limit-reached`), and the shared store, the mail
 *    store and (unless the groupware is disabled) the webmail service reachable (`503`);
 *  - the steps, each recorded with its outcome: remove the shared-folder ACLs the mailbox granted
 *    and received, move its shared-store entries, move its database rows (MailboxDataMover), move
 *    its maildir, rename its SOGo data, re-create the ACLs for the new address;
 *  - a failing step undoes the steps done in reverse order: `503` when every undo succeeded, `500
 *    mailbox-rename-incomplete` when one failed, `504 outcome-unknown` when a step did not answer
 *    within DOCKER_TIMEOUT (that step is left alone, since its command may still run);
 *  - after the steps, the audit record in its own transaction; if it cannot be written, the steps
 *    are undone and the answer is `500`. Then the groupware cache flush.
 *
 * Every step is logged with the request ID, so an interrupted rename can be reconstructed. Failure
 * records are written by the shared refusal auditing from the details the exceptions carry.
 */
class MailboxRenamer
{
    private const HASHES = ['RL_VALUE', TaggedMailHandlingStore::SUBJECT, TaggedMailHandlingStore::SUBFOLDER];

    /** @var array<string, RenameStepOutcome> */
    private array $outcomes = [];

    public function __construct(
        private readonly AddressLock $lock,
        private readonly AddressAvailability $availability,
        private readonly MailStoreCommands $mailStore,
        private readonly MailboxDataMover $mover,
        private readonly MailboxVersion $version,
        private readonly DomainUsageCalculator $usage,
        private readonly SharedStore $store,
        private readonly MemcachedFlusher $flusher,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return string the new address
     */
    public function rename(Principal $principal, string $address, string $newLocalPart, bool $keepOldAddress, ?string $ifMatch): string
    {
        $domain = DB::table('mailbox')->where('username', $address)->where('kind', '')->value('domain');

        if (! is_string($domain)) {
            throw new NotFoundHttpException('The mailbox does not exist.');
        }

        $violations = new FieldViolations;
        $new = MailboxAddress::fromParts($newLocalPart, $domain, $violations, 'newLocalPart');
        $violations->throwIfAny();
        assert($new !== null);

        if ($new->address === $address) {
            $violations->add('newLocalPart', 'unchanged', [], 'The new local part equals the current one.');
            $violations->throwIfAny();
        }

        $this->lock->run([$address, $new->address], function () use ($principal, $address, $new, $keepOldAddress, $ifMatch): void {
            $row = $this->preflight($address, $new, $keepOldAddress, $ifMatch);
            $this->runSteps($principal, $row, $new, $keepOldAddress);
        });

        if (! config('restv2.skip_sogo')) {
            $this->flusher->flush();
        }

        return $new->address;
    }

    private function preflight(string $address, MailboxAddress $new, bool $keepOldAddress, ?string $ifMatch): stdClass
    {
        $row = DB::table('mailbox')->where('username', $address)->where('kind', '')->first();

        if (! $row instanceof stdClass) {
            throw new NotFoundHttpException('The mailbox does not exist.');
        }

        $acl = DB::table('user_acl')->where('username', $address)->first();
        $tags = DB::table('tags_mailbox')->where('username', $address)->pluck('tag_name')->map(fn (mixed $t): string => (string) $t)->all();

        if (ETag::preconditionFails($ifMatch, $this->version->forMailbox($row, $acl, $tags))) {
            throw new PreconditionFailedHttpException('The mailbox was changed since it was read.');
        }

        if (! in_array((int) $row->active, [1, 2], true)) {
            throw new ProblemException(ProblemType::MailboxNotRenamable, 'Only active and login-disabled mailboxes can be renamed.');
        }

        $this->availability->assertFree($new->address);

        if ($keepOldAddress) {
            $limit = (int) DB::table('domain')->where('domain', $new->domain)->value('aliases');
            $count = $this->usage->forDomain($new->domain)->aliases ?? 0;

            if ($count + 1 > $limit) {
                throw new ProblemException(ProblemType::DomainAliasLimitReached, "The domain {$new->domain} already has {$count} of {$limit} aliases; keeping the old address needs one more.", ['domain' => $new->domain, 'count' => $count, 'limit' => $limit, 'requested' => 1]);
            }
        }

        $this->store->ping();

        if (! $this->mailStore->mailStoreReachable()) {
            throw new MailboxDependencyUnavailable('mailStore', 'mailcow\'s container service or the Dovecot container cannot be reached. Nothing was changed.');
        }

        if (! config('restv2.skip_sogo') && ! $this->mailStore->webmailReachable()) {
            throw new MailboxDependencyUnavailable('webmail', 'The SOGo container cannot be reached. Nothing was changed.');
        }

        // The steps must complete or be undone even when the client goes away.
        ignore_user_abort(true);
        set_time_limit(0);

        return $row;
    }

    private function runSteps(Principal $principal, stdClass $row, MailboxAddress $new, bool $keepOldAddress): void
    {
        $old = (string) $row->username;
        $this->outcomes = array_fill_keys(array_column(RenameStep::cases(), 'value'), RenameStepOutcome::NotDone);
        $acls = [];
        $steps = [
            RenameStep::SharedFolderPermissionsRemoval->value => function () use ($old, &$acls): Closure {
                [$acls, $undo] = $this->removeAcls($old);

                return $undo;
            },
            RenameStep::SharedStoreMove->value => fn (): Closure => $this->moveSharedStore($old, $new->address),
            RenameStep::Database->value => fn (): Closure => $this->moveRows($old, $new->address, $keepOldAddress),
            RenameStep::MailStoreMove->value => fn (): Closure => $this->moveMaildir($new->domain, (string) $row->local_part, $new->localPart),
            RenameStep::WebmailRename->value => fn (): ?Closure => $this->renameWebmail($old, $new->address),
            RenameStep::SharedFolderPermissionsRecreation->value => function () use ($old, $new, &$acls): Closure {
                return $this->recreateAcls($acls, $old, $new->address);
            },
        ];
        $undos = [];

        foreach ($steps as $step => $run) {
            try {
                $undo = $run();
                $this->outcomes[$step] = $undo === null ? RenameStepOutcome::Skipped : RenameStepOutcome::Done;

                if ($undo !== null) {
                    $undos[$step] = $undo;
                }

                $this->log($old, $new->address, $step);
            } catch (RenameStepFailure $failure) {
                $this->outcomes[$step] = $failure->outcome;
                $this->log($old, $new->address, $step, $failure->getMessage());
                $this->undo($undos, $old, $new->address);

                throw $this->failed($step, $failure, $old, $new->address, $keepOldAddress);
            }
        }

        try {
            DB::transaction(fn () => $this->audit->record(AuditEventType::MailboxRename, true, $principal, ['type' => 'mailbox', 'id' => $old, 'name' => (string) ($row->name ?? '')], null, null, $this->details($old, $new->address, $keepOldAddress)));
        } catch (Throwable $e) {
            Log::error("The audit record of the rename of {$old} to {$new->address} could not be written; undoing the rename: ".$e->getMessage());
            $this->undo($undos, $old, $new->address);

            if (in_array(RenameStepOutcome::UndoFailed, $this->outcomes, true)) {
                throw $this->incomplete($old, $new->address, $keepOldAddress);
            }

            throw new AuditedFailure('The rename was undone because its audit record could not be written.', $this->details($old, $new->address, $keepOldAddress), $e);
        }
    }

    /**
     * Step 1: read the shared-folder ACLs the mailbox grants and receives, then remove them.
     *
     * @return array{list<array{user: string, id: string, mailbox: string, rights: list<string>}>, Closure(): bool}
     */
    private function removeAcls(string $old): array
    {
        $result = $this->mailStore->getAcls($old);
        $this->assertAnswered($result, 'mailStore', 'The shared-folder permissions could not be read');
        /** @var list<array{user: string, id: string, mailbox: string, rights: list<string>}> $acls */
        $acls = (array) $result->data;
        $removed = [];

        foreach ($acls as $entry) {
            $delete = $this->mailStore->deleteAcl($entry['user'], $entry['mailbox'], $entry['id']);

            if (! $delete->isSuccess()) {
                $restored = $this->setAcls($removed);

                throw new RenameStepFailure('mailStore', 'A shared-folder permission could not be removed: '.$delete->message, match (true) {
                    $delete->isUnknown() => RenameStepOutcome::Unknown,
                    ! $restored => RenameStepOutcome::UndoFailed,
                    default => RenameStepOutcome::Failed,
                });
            }

            $removed[] = $entry;
        }

        return [$acls, fn (): bool => $this->setAcls($removed)];
    }

    /**
     * Step 2: the rate limit and the tagged-mail handling move to the new address in one transaction.
     */
    private function moveSharedStore(string $old, string $new): Closure
    {
        try {
            $moved = $this->store->run(function (Redis $redis) use ($old, $new): array {
                $moved = [];

                foreach (self::HASHES as $hash) {
                    $value = $redis->hGet($hash, $old);

                    if (is_string($value)) {
                        $moved[$hash] = $value;
                    }
                }

                self::exchange($redis, $moved, $old, $new);

                return $moved;
            });
        } catch (Throwable $e) {
            throw new RenameStepFailure('sharedStore', 'mailcow\'s shared store is unavailable: '.$e->getMessage());
        }

        return function () use ($moved, $old, $new): bool {
            try {
                $this->store->run(fn (Redis $redis) => self::exchange($redis, $moved, $new, $old));

                return true;
            } catch (Throwable) {
                return false;
            }
        };
    }

    /**
     * @param  array<string, string>  $values  hash => value
     */
    private static function exchange(Redis $redis, array $values, string $from, string $to): void
    {
        if ($values === []) {
            return;
        }

        $transaction = $redis->multi();

        foreach ($values as $hash => $value) {
            $transaction->hSet($hash, $to, $value);
            $transaction->hDel($hash, $from);
        }

        if (! is_array($transaction->exec())) {
            throw new \RuntimeException('mailcow\'s shared store refused the transaction.');
        }
    }

    /**
     * Step 3: every row, in one transaction.
     */
    private function moveRows(string $old, string $new, bool $keepOldAddress): Closure
    {
        try {
            DB::transaction(fn () => $this->mover->move($old, $new, true, $keepOldAddress ? MailboxDataMover::aliasComment($old, $new) : null));
        } catch (Throwable $e) {
            Log::error("The database step of the rename of {$old} failed: ".$e->getMessage());

            throw new RenameStepFailure('database', 'The database could not be updated.');
        }

        return function () use ($old, $new): bool {
            try {
                DB::transaction(fn () => $this->mover->move($new, $old, false, null));

                return true;
            } catch (Throwable $e) {
                Log::error("The database step of the rename of {$old} could not be undone: ".$e->getMessage());

                return false;
            }
        };
    }

    /**
     * Step 4: the maildir.
     */
    private function moveMaildir(string $domain, string $oldLocal, string $newLocal): Closure
    {
        $this->assertAnswered($this->mailStore->moveMaildir($domain, $oldLocal, $newLocal), 'mailStore', 'The maildir could not be moved');

        return fn (): bool => $this->mailStore->moveMaildir($domain, $newLocal, $oldLocal)->isSuccess();
    }

    /**
     * Step 5: SOGo's data; skipped when the groupware is disabled.
     */
    private function renameWebmail(string $old, string $new): ?Closure
    {
        if (config('restv2.skip_sogo')) {
            return null;
        }

        $this->assertAnswered($this->mailStore->renameWebmailUser($old, $new), 'webmail', 'The webmail data could not be renamed');

        return fn (): bool => $this->mailStore->renameWebmailUser($new, $old)->isSuccess();
    }

    /**
     * Step 6: the ACLs of step 1 again, with the old address replaced by the new one.
     *
     * @param  list<array{user: string, id: string, mailbox: string, rights: list<string>}>  $acls
     */
    private function recreateAcls(array $acls, string $old, string $new): Closure
    {
        $set = [];

        foreach ($acls as $entry) {
            $renamed = ['user' => $entry['user'] === $old ? $new : $entry['user'], 'id' => $entry['id'] === $old ? $new : $entry['id']] + $entry;
            $result = $this->mailStore->setAcl($renamed['user'], $renamed['mailbox'], $renamed['id'], $renamed['rights']);

            if (! $result->isSuccess()) {
                $removed = $this->deleteAcls($set);

                throw new RenameStepFailure('mailStore', 'A shared-folder permission could not be set: '.$result->message, match (true) {
                    $result->isUnknown() => RenameStepOutcome::Unknown,
                    ! $removed => RenameStepOutcome::UndoFailed,
                    default => RenameStepOutcome::Failed,
                });
            }

            $set[] = $renamed;
        }

        return fn (): bool => $this->deleteAcls($set);
    }

    /**
     * @param  list<array{user: string, id: string, mailbox: string, rights: list<string>}>  $entries
     */
    private function setAcls(array $entries): bool
    {
        $ok = true;

        foreach ($entries as $entry) {
            $ok = $this->mailStore->setAcl($entry['user'], $entry['mailbox'], $entry['id'], $entry['rights'])->isSuccess() && $ok;
        }

        return $ok;
    }

    /**
     * @param  list<array{user: string, id: string, mailbox: string, rights: list<string>}>  $entries
     */
    private function deleteAcls(array $entries): bool
    {
        $ok = true;

        foreach ($entries as $entry) {
            $ok = $this->mailStore->deleteAcl($entry['user'], $entry['mailbox'], $entry['id'])->isSuccess() && $ok;
        }

        return $ok;
    }

    private function assertAnswered(DockerCallResult $result, string $dependency, string $what): void
    {
        if ($result->isUnknown()) {
            throw new RenameStepFailure($dependency, "{$what}: the container service did not answer in time.", RenameStepOutcome::Unknown);
        }

        if (! $result->isSuccess()) {
            throw new RenameStepFailure($dependency, "{$what}: ".$result->message);
        }
    }

    /**
     * Undoes the steps done, in reverse order.
     *
     * @param  array<string, Closure(): bool>  $undos
     */
    private function undo(array $undos, string $old, string $new): void
    {
        foreach (array_reverse($undos, true) as $step => $undo) {
            $ok = false;

            try {
                $ok = $undo();
            } catch (Throwable $e) {
                Log::error("Undoing the step {$step} of the rename of {$old} failed: ".$e->getMessage());
            }

            $this->outcomes[$step] = $ok ? RenameStepOutcome::Undone : RenameStepOutcome::UndoFailed;
            $this->log($old, $new, $step);
        }
    }

    private function failed(string $step, RenameStepFailure $failure, string $old, string $new, bool $keepOldAddress): ProblemException
    {
        if (in_array(RenameStepOutcome::UndoFailed, $this->outcomes, true)) {
            return $this->incomplete($old, $new, $keepOldAddress);
        }

        $details = $this->details($old, $new, $keepOldAddress);

        if ($failure->outcome === RenameStepOutcome::Unknown) {
            return new OutcomeUnknown($step, "The step {$step} did not answer in time; its outcome is unknown. Every step known to be done was undone. Check the step before retrying.", $details['steps'], $details);
        }

        return new MailboxDependencyUnavailable($failure->dependency, $failure->getMessage().' Every step already done was undone; nothing was changed.', $details['steps'], $details);
    }

    private function incomplete(string $old, string $new, bool $keepOldAddress): ProblemException
    {
        $details = $this->details($old, $new, $keepOldAddress);

        return new ProblemException(
            ProblemType::MailboxRenameIncomplete,
            "The rename of {$old} to {$new} failed and could not be fully undone; finish or revert the steps marked undoFailed or notDone by hand.",
            ['previousAddress' => $old, 'newAddress' => $new, 'steps' => $details['steps']],
            [],
            $details,
        );
    }

    /**
     * @return array{previousAddress: string, newAddress: string, keepOldAddressAsAlias: bool, steps: list<array{step: string, outcome: string}>}
     */
    private function details(string $old, string $new, bool $keepOldAddress): array
    {
        $steps = [];

        foreach ($this->outcomes as $step => $outcome) {
            $steps[] = ['step' => $step, 'outcome' => $outcome->value];
        }

        return ['previousAddress' => $old, 'newAddress' => $new, 'keepOldAddressAsAlias' => $keepOldAddress, 'steps' => $steps];
    }

    private function log(string $old, string $new, string $step, ?string $message = null): void
    {
        Log::info("Mailbox rename {$old} to {$new}: step {$step} ".$this->outcomes[$step]->value.($message === null ? '' : " ({$message})"));
    }
}
