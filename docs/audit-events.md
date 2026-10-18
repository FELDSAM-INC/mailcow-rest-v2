# Audit event details

This file documents, for every audit event type, the keys of `AuditEvent.details`, following
`docs/conventions.md` ("Audit record details"). Each feature adds its section when it is
implemented. Keys are camelCase and stable, and never hold secrets.

Each section lists the keys of one `eventType`. "Present" says when a key appears; a key that is
not listed for an event type never appears in its records. An event type without keys has an empty
`details` object. The code keeps only the documented keys of each event type
(`App\Audit\AuditEventType::detailKeys()`), and `tests/Unit/Audit/AuditEventDetailsDocumentedTest.php`
checks that this file and the code agree.

<!-- Sections are added per feature, starting with 001. -->

## signIn

Feature 001. Written for every completed sign-in and for every refused sign-in step: a password
sign-in, the second-factor step of a pending sign-in, and a passkey sign-in. Opening a pending
sign-in (password verified, second factor outstanding) is not recorded; its outcome is.
`authMethod` is `password`, `passwordAndSecondFactor` or `passkey`; `failureReason` says why a step
was refused.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `requestedRole` | string or null | The role the sign-in named (`admin`, `domainAdmin`, `mailboxUser`), or `null` when it named none. For the second-factor step, the role named in the password step. | always |
| `secondFactorType` | string | The second factor used or attempted: `totp`, `webauthn` or `yubiOtp`. | when a second factor completed the sign-in, and on every failure of the second-factor step |
| `restricted` | boolean | Whether the new session is restricted by pending actions (forced password change or second-factor setup). | on success only |
| `step` | string | The refused step: `password`, `secondFactor` or `passkey`. | on failure only |

## signOut

Feature 001. Written when a signed-in session signs out (`DELETE /session`). Signing out without a
session, or during a pending sign-in, writes nothing. No keys.

## preferencesUpdate

Feature 001. Written when `PATCH /me/preferences` changes at least one stored value. A request that
changes nothing writes no record.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changedFields` | array of strings | The preferences whose stored value changed: `language`, `theme`. | always |

## passkeyRegistration

Feature 001. Written for every registration attempt that reaches the authenticator response
check (`POST /me/passkeys`): success, or failure with `failureReason: registrationFailed` (options
missing, used or expired, response rejected, or credential already registered). The target is the
passkey (`id` is its SHA-256 identifier, `name` its display name) on success, and `null` on failure.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `restrictionLifted` | boolean | Whether the registration lifted a pending second-factor setup of the session. | on success only |

## passkeyRename

Feature 001. Written when `PATCH /me/passkeys/{passkeyId}` changes the display name. The target
carries the new name. No keys.

## passkeyRemoval

Feature 001. Written when `DELETE /me/passkeys/{passkeyId}` removes a passkey. The target carries
the name it had. No keys.

## apiKeyCreation

Feature 001. Written when a signed-in administrator creates an API key (`POST /api-keys`). The
target is the key (`id` is its ULID, `name` its name). The secret never appears in the record.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `ownerUsername` | string | The account the key acts as: an administrator or a domain administrator. | always |
| `accessLevel` | string | `readOnly` or `readWrite`. | always |
| `expiresAt` | string (RFC 3339, UTC) or null | When the key expires, or `null` for a key that never expires. | always |
| `allowedNetworks` | array of strings | The normalised networks (`address/prefix`) the key may be used from; empty means any address. | always |

## apiKeyRevocation

Feature 001. Written for the first revocation of an API key
(`POST /api-keys/{apiKeyId}/revocation`). Revoking an already revoked key writes nothing. The
target is the key.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `ownerUsername` | string | The account the key acted as. | always |

<!-- Feature 002: domains, their footer and domain templates. -->

## Refusal records (features 002 onwards)

State-changing operations from feature 002 onwards declare refusal auditing
(`docs/conventions.md`, "Refusal auditing"): every refusal of an authenticated caller writes one
record with `outcome: failure`, a shared `failureReason` and these keys, whatever refused it (the
request pipeline, the role, the scope, a conflict, an outdated version, a validation failure or
mailcow's unavailable shared store). CSRF failures and requests without credentials write none. The
keys below appear only in refusal records and are listed in the table of every event type that can
be refused:

- `problemType` (string): the problem's `type`, a `/api/v2/problems/<name>` URI or `about:blank`.
- `status` (integer): the HTTP status of the refusal.
- `errors` (object, only for `422`): each invalid field with its violations as `{code, params}`,
  never the submitted values.

A refused bulk request is recorded once, with a `null` target, `targetIds` (the requested IDs, at
most 100, each cut to 255 characters) and `bulkAction`. Items of a processed bulk request are
recorded one by one, each with `bulkRequestId`.

## domainCreation

Feature 002. Written for every creation of a domain (`POST /domains`), in the transaction that
stores it, and for every refused creation. The target is the domain (`id` its ASCII name, `name` its
readable name). The record is written with `creationReport: "pending"` and completed once after the
follow-up steps have run.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The stored attributes in contract names: `description`, `active`, `maxMailboxes`, `maxAliases`, `defaultMailboxQuota`, `maxMailboxQuota`, `domainQuota` (bytes), `backupMx`, `relayAllRecipients`, `relayUnknownOnly`, `globalAddressList`, `tags`, the initial `rateLimit` (`{value, frame}` or `null`), `dkimSelector` (empty: no key) and `dkimKeySize`. | on success |
| `templateId` | integer or null | The domain template the values came from; `null` when the built-in values applied because no Default template exists. | on success |
| `creationReport` | object | The creation report of the response (`DomainCreationReport`): `rateLimit`, `dkimKey`, `dkimDnsPublication`, `groupwareDirectoryUpdate`, `groupwareRestart` and, when a step failed, `messages`. `"pending"` only until the follow-up steps complete. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainUpdate

Feature 002. Written for every edit of a domain (`PATCH /domains/{domain}`), for every activation or
deactivation of a bulk action, and for every refusal of them. The target is the domain. An edit that
changes nothing is recorded too, with empty `changes`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed attribute (contract names) as `{old, new}`: quotas in bytes, `tags` as sorted lists, `description` after the empty-means-name rule, relay options after normalisation. Empty when nothing changed. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainDeletion

Feature 002. Written for every deletion of a domain (`DELETE /domains/{domain}` and bulk
deletions), in the transaction that deletes it, and for every refusal. The target is the deleted
domain. The record is written with `maildirCleanup: "pending"` and completed once after mailcow's
container service was asked to move the stored mail to the garbage area. A footer removed with the
domain is recorded here, not in a record of its own.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removedAliasDomains` | array of strings | ASCII names of the alias domains deleted with the domain, sorted. | on success |
| `removedDomainAdmins` | array of strings | Domain administrator accounts deleted because they were left without any domain, sorted. Their API keys stop working. | on success |
| `footerRemoved` | boolean | Whether a footer was stored and deleted with the domain. | on success |
| `maildirCleanup` | object | The stored-mail move: `outcome` (`done`, `failed` or `unknown` after a timeout) and, unless done, `message` (English). `"pending"` only until the move was requested. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

<!-- Feature 010: DKIM keys. The DKIM core is delivered with feature 002. -->

## dkimKeyGeneration

Feature 010, written since feature 002. Written when a DKIM key is generated, also as a follow-up
step of a domain creation (and, with feature 004, of an alias domain creation): the key's history is
complete even though the creation has its own record. The target is the key (`type: dkimKey`, `id`
the name, `name` its readable form). No private key material and no ISPConfig password ever appear.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `selector` | string | The selector of the new key. | on success |
| `keyLength` | integer or null | Bits of the new key. | on success |
| `overwriteRequested` | boolean | Whether the request asked to replace an existing key (always `false` for creations). | on success |
| `old` | object | The replaced key: `selector`, `keyLength`, `publicKeySha256` (hex SHA-256 of the DER public key). | when a key was replaced (feature 010) |
| `new` | object | The new key: `selector`, `keyLength`, `publicKeySha256`. | on success |
| `trigger` | string | What generated the key: `api` (feature 010), `domainCreation` or `aliasDomainCreation`. | on success |
| `dnsPublication` | object | The FeldHost ISPConfig publication: `status` (`DkimDnsPublicationStatus`), `reasonCode` (`DkimDnsPublicationReasonCode` or `null`) and `detail` (technical text without secrets, or `null`). | on success |
| `problemType` | string | See "Refusal records". | on failure (feature 010) |
| `status` | integer | See "Refusal records". | on failure (feature 010) |
| `errors` | object | See "Refusal records". | on `422` failures (feature 010) |
