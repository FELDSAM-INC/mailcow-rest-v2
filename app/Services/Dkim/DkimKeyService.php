<?php

namespace App\Services\Dkim;

use App\Auth\Principal;
use App\Clients\SharedStore\DkimKeyStore;
use App\Clients\SharedStore\DkimStoreResult;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Exceptions\ProblemException;
use App\Services\Audit\AuditContext;
use App\Support\Http\ETag;
use App\Support\Http\ProblemType;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\PreconditionFailedHttpException;
use Throwable;

/**
 * The single entry point of every DKIM key write (feature 010 research R10). Features 002 and 004
 * call generateForNewName() when they create domains and alias domains; feature 010 adds its
 * operations here.
 *
 * Order of a change (research R13): the atomic store write, then the DNS publication (whose failure
 * never undoes the key), then the audit record. When the audit record cannot be written, the
 * previous key is restored (only while the stored key is still the one just written), so that no
 * key change stays unaudited.
 */
class DkimKeyService
{
    public function __construct(
        private readonly DkimKeyStore $store,
        private readonly DkimKeyGenerator $generator,
        private readonly DkimDnsPublisher $publisher,
        private readonly DkimAudit $audit,
        private readonly DkimNameResolver $names,
        private readonly DkimEntryQuery $entries,
    ) {}

    /**
     * generateDkimKey (FR-013 to FR-015): a new key pair for a domain or alias domain in the
     * caller's scope (else `404`). An existing key, complete or not, is a conflict unless an
     * administrator asks to overwrite it (a domain administrator asking is `403`); the overwrite
     * replaces the whole old key atomically, checked against `If-Match`.
     */
    public function generate(Principal $principal, string $rawName, ?string $selector, ?int $bits, bool $overwrite, ?string $ifMatch): DkimKeyChange
    {
        if ($overwrite) {
            Gate::forUser($principal)->authorize('dkim.overwrite');
        }

        $name = $this->names->resolve($principal, $rawName);

        if ($name === null || ! $name->isMailDomain()) {
            throw new NotFoundHttpException('The name is neither a domain nor an alias domain.');
        }

        $selector ??= (string) config('restv2.dkim.default_selector');
        $current = $this->current($name->name, $overwrite, $ifMatch);
        $material = $this->generator->generate($bits ?? (int) config('restv2.dkim.default_key_size'));

        return $this->commitChange(
            new AuditContext($principal),
            $name,
            $selector,
            $material,
            $current,
            $ifMatch,
            fn (AuditContext $context, DkimDnsPublicationResult $publication, ?StoredDkimKey $old): string => $this->audit->generation($context, $name->name, $selector, $material->publicKey, $publication, $overwrite, $old),
        );
    }

    /**
     * The stored key before a write: a conflict when it exists and no overwrite was asked for, and
     * `412` when `If-Match` does not match it (or there is no key to match).
     */
    private function current(string $name, bool $overwrite, ?string $ifMatch): ?StoredDkimKey
    {
        $current = $this->store->read($name);

        if ($current !== null && ! $overwrite) {
            throw new ProblemException(ProblemType::DkimKeyExists, "{$name} already has a DKIM key. Send \"overwrite\" to replace it.");
        }

        $this->checkVersion($current, $ifMatch);

        return $current;
    }

    private function checkVersion(?StoredDkimKey $current, ?string $ifMatch): void
    {
        if ($ifMatch === null || trim($ifMatch) === '') {
            return;
        }

        if ($current === null || ETag::preconditionFails($ifMatch, $this->store->versionTag($current))) {
            throw new PreconditionFailedHttpException('The DKIM key was changed since it was read.');
        }
    }

    /**
     * The shared write step of generation, import and copy (research R13): the atomic store write
     * (`create`, or `replace` compared with the key read when `If-Match` was sent), the DNS
     * publication, which never undoes the key, and the audit record. When the record cannot be
     * written, the previous key is put back (only while the stored key is still the one just
     * written), the failure is logged with the name, and the exception ends the request (`500`).
     *
     * @param  callable(AuditContext, DkimDnsPublicationResult, ?StoredDkimKey): string  $audit
     */
    private function commitChange(AuditContext $context, DkimName $name, string $selector, DkimKeyMaterial $material, ?StoredDkimKey $current, ?string $ifMatch, callable $audit): DkimKeyChange
    {
        if ($current === null) {
            $result = $this->store->create($name->name, $selector, $material);

            if ($result->status === 'exists') {
                throw new ProblemException(ProblemType::DkimKeyExists, "{$name->name} already has a DKIM key. Send \"overwrite\" to replace it.");
            }
        } else {
            $result = $this->store->replace($name->name, $selector, $material, $ifMatch === null || trim($ifMatch) === '' ? null : $current->fingerprint);

            if ($result->status === 'outdated') {
                throw new PreconditionFailedHttpException('The DKIM key was changed since it was read.');
            }
        }

        $old = $current === null ? null : self::old($name->name, $result);
        $publication = $this->publisher->publish($name->name, $selector, $material->publicKey, $result->oldSelector);

        try {
            $audit($context, $publication, $old);
        } catch (Throwable $e) {
            $written = DkimKeyStore::fingerprint($name->name, $selector, $material->publicKey, $material->privateKey);
            $this->restoreAfterFailedAudit($name->name, $written, $result);

            throw $e;
        }

        return new DkimKeyChange($this->entries->entry($name), $current === null, $publication, $result->oldSelector, $result->oldPublicKey);
    }

    /**
     * Puts the replaced fields back (none after a creation), logging the outcome with the name.
     */
    private function restoreAfterFailedAudit(string $name, string $writtenFingerprint, DkimStoreResult $result): void
    {
        try {
            $restored = $this->store->restore($name, $writtenFingerprint, $result->oldSelector, $result->oldPublicKey, $result->oldPrivateKey)->ok();
            Log::error($restored
                ? "The DKIM key change of {$name} was undone because its audit record could not be written."
                : "The DKIM key change of {$name} could not be undone after its audit record failed: the key changed again meanwhile.");
        } catch (Throwable $e) {
            Log::error("The DKIM key change of {$name} could not be undone after its audit record failed: ".$e->getMessage());
        }
    }

    private static function old(string $name, DkimStoreResult $result): StoredDkimKey
    {
        return new StoredDkimKey($name, $result->oldSelector, $result->oldPublicKey, $result->oldPrivateKey !== null, null, '');
    }

    /**
     * The DKIM follow-up step of a creation (002 FR-027, 004 FR-042, 010 FR-016). An existing key
     * (a public key or a selector stored for the name) is kept. Never throws: a store or audit
     * failure gives `failed`, a publication failure is reported in the step.
     */
    public function generateForNewName(string $asciiName, string $selector, int $bits, AuditContext $context): DkimCreationStep
    {
        try {
            if ($this->store->read($asciiName) !== null) {
                return new DkimCreationStep(DkimCreationStep::ALREADY_EXISTED);
            }

            $material = $this->generator->generate($bits);
            $result = $this->store->create($asciiName, $selector, $material);
        } catch (SharedStoreUnavailable $e) {
            Log::error("The DKIM key of {$asciiName} was not created: mailcow's shared store is unavailable.");

            return new DkimCreationStep(DkimCreationStep::FAILED, null, 'mailcow\'s shared store is unavailable.');
        } catch (Throwable $e) {
            Log::error("The DKIM key of {$asciiName} was not created: ".$e->getMessage());

            return new DkimCreationStep(DkimCreationStep::FAILED, null, 'The key could not be generated.');
        }

        if (! $result->ok()) {
            return new DkimCreationStep(DkimCreationStep::ALREADY_EXISTED);
        }

        $publication = $this->publisher->publish($asciiName, $selector, $material->publicKey);

        try {
            $this->audit->generation($context, $asciiName, $selector, $material->publicKey, $publication);
        } catch (Throwable $e) {
            $this->compensate($asciiName, $selector, $material);
            Log::error("The DKIM key of {$asciiName} was removed again because its audit record could not be written: ".$e->getMessage());

            return new DkimCreationStep(DkimCreationStep::FAILED, null, 'The key change could not be audited.');
        }

        return new DkimCreationStep(DkimCreationStep::CREATED, $publication);
    }

    private function compensate(string $name, string $selector, DkimKeyMaterial $material): void
    {
        try {
            $written = DkimKeyStore::fingerprint($name, $selector, $material->publicKey, $material->privateKey);
            $this->store->restore($name, $written, null, null, null);
        } catch (Throwable $e) {
            Log::error("The DKIM key of {$name} could not be removed after the failed audit: ".$e->getMessage());
        }
    }
}
