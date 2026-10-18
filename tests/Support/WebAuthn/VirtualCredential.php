<?php

namespace Tests\Support\WebAuthn;

use OpenSSLAsymmetricKey;

/**
 * A credential of the VirtualAuthenticator: an EC P-256 key pair bound to a relying party.
 */
final class VirtualCredential
{
    public function __construct(
        /** Raw credential ID bytes (mailcow `fido2.credentialId`). */
        public readonly string $id,
        public readonly string $rpId,
        /** User handle (legacy registers the username). */
        public readonly string $userHandle,
        public readonly OpenSSLAsymmetricKey $privateKey,
        /** PEM public key, as lbuchs stores it (mailcow `credentialPublicKey` / `publicKey`). */
        public readonly string $publicKeyPem,
        /** COSE_Key (CBOR) of the public key. */
        public readonly string $coseKey,
        /** Raw 16-byte AAGUID. */
        public readonly string $aaguid,
        public int $signCount = 0,
    ) {}
}
