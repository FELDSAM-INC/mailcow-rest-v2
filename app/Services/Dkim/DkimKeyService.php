<?php

namespace App\Services\Dkim;

use App\Audit\AuditEventType;
use App\Auth\Principal;
use App\Clients\SharedStore\DkimKeyStore;
use App\Clients\SharedStore\DkimStoreResult;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Exceptions\ProblemException;
use App\Http\RequestContext;
use App\Rules\DkimPrivateKey;
use App\Services\Audit\AuditContext;
use App\Services\Config\MailcowConfig;
use App\Support\Bulk\BulkActionRunner;
use App\Support\Clock\Clock;
use App\Support\Http\ETag;
use App\Support\Http\FieldViolations;
use App\Support\Http\ProblemType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        private readonly Clock $clock,
    ) {}

    /**
     * importDkimKey (FR-017 to FR-021): an unencrypted RSA private key (validated by
     * App\Rules\DkimPrivateKey) for any valid domain name, which need not be a domain or alias
     * domain (its key is then unused until that name is created). The public key is derived as
     * legacy derives it and the normalised text is stored. An existing key needs `overwrite`.
     */
    public function import(Principal $principal, string $rawName, #[\SensitiveParameter] string $privateKey, ?string $selector, bool $overwrite, ?string $ifMatch): DkimKeyChange
    {
        Gate::forUser($principal)->authorize('dkim.import');

        $ascii = DkimNameResolver::normalize($rawName);

        if ($ascii === null) {
            $violations = new FieldViolations;
            $violations->add('dkimName', 'invalidFormat', ['format' => 'domainName']);
            $violations->throwIfAny();
        }

        $name = $this->names->kindOf((string) $ascii);
        $text = DkimPrivateKey::normalize($privateKey);
        $public = $this->generator->publicKeyOf($text);

        if ($public === null) {
            $violations = new FieldViolations;
            $violations->add('privateKey', 'invalidPrivateKey', [], 'Not exactly one PEM-encoded private key (PKCS #1 or PKCS #8).');
            $violations->throwIfAny();
        }

        $material = new DkimKeyMaterial((string) $public, $text);
        $selector ??= (string) config('restv2.dkim.default_selector');
        $current = $this->current($name->name, $overwrite, $ifMatch);

        return $this->commitChange(
            new AuditContext($principal),
            $name,
            $selector,
            $material,
            $current,
            $ifMatch,
            fn (AuditContext $context, DkimDnsPublicationResult $publication, ?StoredDkimKey $old): string => $this->audit->import($context, $name->name, $selector, $material->publicKey, $publication, $overwrite, $old),
        );
    }

    /**
     * copyDkimKey (FR-022, FR-023): the selector, public key and private key of a source with a key
     * (valid, or unused by its exact name) go to one target that is a domain or alias domain and
     * differs from the source (`422 notFound`, `sameAsSource`). An incomplete source is a `409`
     * naming the missing parts; an existing target key needs `overwrite`, and `If-Match` applies to
     * the target's key. The source is unchanged.
     */
    public function copy(Principal $principal, string $rawSource, string $rawTarget, bool $overwrite, ?string $ifMatch): DkimKeyChange
    {
        Gate::forUser($principal)->authorize('dkim.copy');

        $source = $this->names->resolve($principal, $rawSource);
        $sourceKey = $source === null ? null : $this->store->read($source->name);

        if ($source === null || $sourceKey === null) {
            throw new NotFoundHttpException('The source has no DKIM key.');
        }

        $targetName = DkimNameResolver::normalize($rawTarget);
        $target = $targetName === null ? null : $this->names->kindOf($targetName);
        $violations = new FieldViolations;

        if (trim($rawTarget) === $source->name || $targetName === $source->name) {
            $violations->add('target', 'sameAsSource', [], 'The target must differ from the source.');
        } elseif ($target === null || ! $target->isMailDomain()) {
            $violations->add('target', 'notFound', [], 'The target is neither a domain nor an alias domain.');
        }

        $violations->throwIfAny();
        assert($target !== null);

        $missing = array_keys(array_filter(['publicKey' => $sourceKey->publicKey === null, 'selector' => $sourceKey->selector === null, 'privateKey' => ! $sourceKey->hasPrivateKey]));
        $private = $missing === [] ? $this->store->privateKey($source->name, (string) $sourceKey->selector) : null;

        if ($missing !== [] || $private === null) {
            throw new ConflictHttpException('The source key is incomplete ('.implode(', ', $missing === [] ? ['privateKey'] : $missing).' missing), so it cannot be copied.');
        }

        $selector = (string) $sourceKey->selector;
        $material = new DkimKeyMaterial((string) $sourceKey->publicKey, $private);
        $current = $this->current($target->name, $overwrite, $ifMatch);

        return $this->commitChange(
            new AuditContext($principal),
            $target,
            $selector,
            $material,
            $current,
            $ifMatch,
            fn (AuditContext $context, DkimDnsPublicationResult $publication, ?StoredDkimKey $old): string => $this->audit->copy($context, $target->name, $selector, $material->publicKey, $publication, $overwrite, $old, $source->name),
        );
    }

    /**
     * deleteDkimKey (FR-024, FR-025): the public key, selector and private key of a name go together;
     * a name without a key is `404`, an outdated `If-Match` `412`. The record is removed from the
     * ISPConfig zone (when enabled) and the outcome goes into the audit record.
     */
    public function delete(Principal $principal, string $rawName, ?string $ifMatch): DkimDnsPublicationResult
    {
        Gate::forUser($principal)->authorize('dkim.delete');

        $name = $this->names->resolve($principal, $rawName) ?? throw new NotFoundHttpException('The name has no DKIM key.');

        return $this->deleteName(new AuditContext($principal), $name, $ifMatch, null);
    }

    /**
     * applyDkimKeyBulkAction with `delete` (FR-059, research R28): every target is resolved and
     * deduplicated first (BulkActionRunner), then deleted on its own like deleteDkimKey, with the
     * request ID in its audit record. While the publication is enabled, the record removals share
     * RESTV2_DKIM_BULK_PUBLICATION_BUDGET seconds: each gets at most 15 seconds of what is left, and
     * once it is spent the remaining keys are still deleted and report `failed` with `timeout`.
     *
     * @param  list<array{id: string, version?: string|null}>  $targets
     * @return array{data: array{bulkRequestId: string, results: list<array<string, mixed>>, summary: array<string, int>}, meta?: array{dnsPublication: array<string, array<string, mixed>>}}
     */
    public function bulkDelete(Request $request, Principal $principal, array $targets, BulkActionRunner $runner): array
    {
        Gate::forUser($principal)->authorize('dkim.delete');

        $enabled = IspconfigSettings::fromConfig()->enabled();
        $budget = (float) config('restv2.dkim.bulk_publication_budget', 60);
        /** @var array<string, DkimName> $names */
        $names = [];
        /** @var array<string, string> $ids */
        $ids = [];
        $publications = [];
        $context = null;

        $result = $runner->run(
            $request,
            $principal,
            AuditEventType::DkimKeyDeletion,
            $targets,
            function (string $id) use ($principal, &$names, &$ids): ?string {
                $name = $this->names->resolve($principal, $id);

                if ($name === null) {
                    return null;
                }

                $names[$name->name] = $name;
                $ids[$name->name] ??= $id;

                return $name->name;
            },
            function (string $key, ?string $ifMatch) use ($request, $principal, $enabled, &$budget, &$names, &$ids, &$publications, &$context): ?string {
                $context ??= (new AuditContext($principal))->withBulkRequestId(RequestContext::requestId($request));
                $started = $this->clock->now();
                $publication = $this->deleteName($context, $names[$key], $ifMatch, $enabled ? $budget : null);
                $budget -= ($this->clock->now()->getTimestampMs() - $started->getTimestampMs()) / 1000;
                $publications[$ids[$key]] = $publication->toArray();

                return null;
            },
            fn (string $id, ?string $key): ?array => $key === null ? DkimAuditTargets::named($id) : DkimAuditTargets::target($key),
        );

        return $enabled ? ['data' => $result, 'meta' => ['dnsPublication' => $publications]] : ['data' => $result];
    }

    /**
     * publishDkimDnsRecord (FR-031): the record of the name's current key published again, with the
     * same steps and outcomes as after a key change. Administrators only; `403
     * dkim-dns-publication-disabled` while the publication is off; a name without a key, or with a
     * key lacking its selector or public key (nothing to publish), is `404`; `If-Match` applies to
     * the key. The key is unchanged; the outcome is audited.
     */
    public function publishAgain(Principal $principal, string $rawName, ?string $ifMatch): DkimKeyChange
    {
        Gate::forUser($principal)->authorize('dkim.publish');

        if (! IspconfigSettings::fromConfig()->enabled()) {
            throw new ProblemException(ProblemType::DkimDnsPublicationDisabled);
        }

        $name = $this->names->resolve($principal, $rawName) ?? throw new NotFoundHttpException('The name has no DKIM key.');
        $key = $this->store->read($name->name);

        if ($key === null || $key->selector === null || $key->publicKey === null) {
            throw new NotFoundHttpException('The name has no DKIM key to publish.');
        }

        $this->checkVersion($key, $ifMatch);
        $publication = $this->publisher->publish($name->name, $key->selector, $key->publicKey);
        $this->audit->publication(new AuditContext($principal), $name->name, $key->selector, $publication);

        return new DkimKeyChange(new DkimEntry($name, $key), false, $publication);
    }

    /**
     * exportDkimPrivateKey (FR-026, research R9), in this order: administrators only (`403
     * forbidden`), only while SHOW_DKIM_PRIV_KEYS is enabled (`403 dkim-private-key-export-disabled`),
     * a name in scope (`404`) with a private key (`404`), then the audit record; when it cannot be
     * written, the key is not returned. Never logged.
     *
     * @return array{name: string, selector: string, privateKey: string}
     */
    public function export(Principal $principal, string $rawName, MailcowConfig $config): array
    {
        Gate::forUser($principal)->authorize('dkim.export');

        if (! $config->showDkimPrivateKeys()) {
            throw new ProblemException(ProblemType::DkimPrivateKeyExportDisabled);
        }

        $name = $this->names->resolve($principal, $rawName) ?? throw new NotFoundHttpException('The name has no DKIM key.');
        $key = $this->store->read($name->name);
        $private = $key === null || $key->selector === null ? null : $this->store->privateKey($name->name, $key->selector);

        if ($key === null || $key->selector === null || $private === null) {
            throw new NotFoundHttpException('The name has no DKIM private key.');
        }

        $this->audit->export(new AuditContext($principal), $name->name, $key->selector, $key->publicKey);

        return ['name' => $name->name, 'selector' => $key->selector, 'privateKey' => $private];
    }

    /**
     * The deletion of a resolved name: the store's `delete` (compared with the key read when
     * `If-Match` was sent), the record removal within the given budget, and the audit record (the
     * key is put back when the record cannot be written).
     */
    private function deleteName(AuditContext $context, DkimName $name, ?string $ifMatch, ?float $budget): DkimDnsPublicationResult
    {
        $current = $this->store->read($name->name) ?? throw new NotFoundHttpException('The name has no DKIM key.');
        $this->checkVersion($current, $ifMatch);
        $result = $this->store->delete($name->name, $ifMatch === null || trim($ifMatch) === '' ? null : $current->fingerprint);

        if ($result->status === 'absent') {
            throw new NotFoundHttpException('The name has no DKIM key.');
        }

        if ($result->status === 'outdated') {
            throw new PreconditionFailedHttpException('The DKIM key was changed since it was read.');
        }

        $publication = $this->removeRecord($name->name, $result->oldSelector, $budget);

        try {
            $this->audit->deletion($context, $name->name, self::old($name->name, $result), $publication);
        } catch (Throwable $e) {
            $this->restoreAfterFailedAudit($name->name, DkimKeyStore::fingerprint($name->name, null, null, null), $result);

            throw $e;
        }

        return $publication;
    }

    private function removeRecord(string $name, ?string $selector, ?float $budget): DkimDnsPublicationResult
    {
        $enabled = IspconfigSettings::fromConfig()->enabled();

        if (! $enabled || $selector === null) {
            return $enabled
                ? new DkimDnsPublicationResult(DkimDnsOutcome::NoRecordToRemove, null)
                : new DkimDnsPublicationResult(DkimDnsOutcome::NotConfigured, null);
        }

        if ($budget !== null && $budget < 0.5) {
            return new DkimDnsPublicationResult(DkimDnsOutcome::Failed, $selector.'._domainkey', 'timeout', 'The publication time of the bulk request was spent.');
        }

        return $this->publisher->remove($name, $selector, $budget);
    }

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
