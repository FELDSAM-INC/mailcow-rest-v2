<?php

namespace App\Services\Dkim;

use App\Clients\SharedStore\DkimKeyStore;
use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Services\Audit\AuditContext;
use Illuminate\Support\Facades\Log;
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
    ) {}

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
