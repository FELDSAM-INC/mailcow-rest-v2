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
sign-in, the second-factor step of a pending sign-in, and a passkey sign-in. Feature 012 adds the
single sign-on completion (`authMethod: singleSignOn`; its failures, a refused state and a
provider-error report included), password sign-ins verified by the LDAP directory
(`directoryPassword`, `directoryPasswordAndSecondFactor`) or by Keycloak's mailpassword flow
(`providerPassword`, `providerPasswordAndSecondFactor`); the actor of a failure after the provider
named the user is the provider's e-mail address. Opening a pending
sign-in (password verified, second factor outstanding) is not recorded; its outcome is.
`authMethod` is `password`, `passwordAndSecondFactor` or `passkey`; `failureReason` says why a step
was refused. Feature 007 adds the single sign-on token sign-in (`authMethod: ssoToken`,
`requestedRole: domainAdmin`, `step: ssoToken` on failure), never with the token.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `requestedRole` | string or null | The role the sign-in named (`admin`, `domainAdmin`, `mailboxUser`), or `null` when it named none. For the second-factor step, the role named in the password step. | always |
| `secondFactorType` | string | The second factor used or attempted: `totp`, `webauthn` or `yubiOtp`. | when a second factor completed the sign-in, and on every failure of the second-factor step |
| `restricted` | boolean | Whether the new session is restricted by pending actions (forced password change or second-factor setup). | on success only |
| `step` | string | The refused step: `password`, `secondFactor`, `passkey` or (feature 007) `ssoToken`. | on failure only |
| `identityProvider` | string | Feature 012: the provider of a sign-in through it (`keycloak`, `genericOidc`, `ldap`). | for single sign-on and provider password sign-ins, when the provider type is known |
| `mailboxCreated` | boolean | Feature 012: whether the sign-in created the mailbox (first sign-in). | on success of a sign-in through the provider |
| `templateApplied` | boolean | Feature 012: whether the mailbox was updated from its mapped template before the session was established. | on success of a sign-in through the provider |
| `providerError` | string | Feature 012: the OAuth 2.0 error code the provider returned the browser with (`failureReason: providerReturnedError`). | on provider-error reports |
| `cause` | string | Feature 012: why the mailbox could not be created on first sign-in (`failureReason: mailboxCreationFailed`): `templateMissing`, `domainNotFound`, `addressInUse`, `mailboxLimitReached`, `quotaExceeded`, `temporaryFailure` or `validationFailed`. | on `mailboxCreationFailed` failures |

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
| `values` | object | The stored attributes in contract names: `description`, `active`, `maxMailboxes`, `maxAliases`, `maxMailboxQuota`, `domainQuota` (bytes), `backupMx`, `relayAllRecipients`, `relayUnknownOnly`, `globalAddressList`, `tags`, the initial `rateLimit` (`{value, frame}` or `null`), `dkimSelector` (empty: no key) and `dkimKeySize`. The default mailbox quota that the server keeps for the classic interface is not recorded; records written before 2026-10-18 also carry `defaultMailboxQuota` (bytes), from before the owner removed it from the API. | on success |
| `templateId` | integer or null | The domain template the values came from; `null` when the built-in values applied because no Default template exists. | on success |
| `creationReport` | object | The creation report of the response (`DomainCreationReport`): `rateLimit`, `dkimKey`, `dkimDnsPublication`, `groupwareRestart` and, when a step failed, `messages`. `"pending"` only until the follow-up steps complete. Records written before 2026-10-18 also carry `groupwareDirectoryUpdate`, from the directory rebuild that creations no longer run (legacy never did). | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainUpdate

Feature 002. Written for every edit of a domain (`PATCH /domains/{domain}`), for every activation or
deactivation of a bulk action, and for every refusal of them. The target is the domain. An edit that
changes nothing is recorded too, with empty `changes`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed attribute (contract names) as `{old, new}`: quotas in bytes, `tags` as sorted lists, `description` after the empty-means-name rule, relay options after normalisation. Empty when nothing changed. The default mailbox quota that a lowered `maxMailboxQuota` lowers with it is not recorded; records written before 2026-10-18 may carry `defaultMailboxQuota`. | on success |
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

## domainFooterUpdate

Feature 002. Written for every replacement of a domain's footer
(`PUT /domains/{domain}/footer`) and every refusal. The target is the footer (`type: domainFooter`,
`id` the domain's ASCII name, `name` its readable name). The texts never appear, only their sizes.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | `html` and `plain` as `{changed, oldSize, newSize}` (sizes in bytes, always present); `skipReplies`, `excludedAddresses` and `excludedAliasDomains` as `{old, new}` when they changed. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainTemplateCreation

Feature 002. Written for every creation of a domain template (`POST /domain-templates`) and every
refusal. The target is the template (`type: domainTemplate`, `id` its ID, `name` its name; a refused
creation has no ID).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The template's values in contract names: `tags`, `maxMailboxes`, `maxAliases`, `maxMailboxQuota`, `domainQuota` (bytes), `rateLimit` (`{value, frame}` or `null`), `active`, `globalAddressList`, `backupMx`, `relayAllRecipients`, `relayUnknownOnly`, `dkimSelector` and `dkimKeySize`. The stored default mailbox quota (`def_quota_for_mbox`), kept for the classic interface, is not recorded; records written before 2026-10-18 also carry `defaultMailboxQuota`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainTemplateUpdate

Feature 002. Written for every edit of a domain template (`PATCH /domain-templates/{templateId}`),
also when nothing changes, and every refusal. The target carries the name after the edit.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed member (`name` and the values of `domainTemplateCreation`) as `{old, new}`. Empty when nothing changed. An adjustment of the stored default mailbox quota is not recorded; records written before 2026-10-18 may carry `defaultMailboxQuota`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainTemplateDeletion

Feature 002. Written for every deletion of a domain template
(`DELETE /domain-templates/{templateId}` and, since feature 003, bulk deletion through
`POST /domain-templates/bulk-actions`) and every refusal. The target is the deleted template.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The values the template had, as in `domainTemplateCreation`. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

<!-- Feature 010: DKIM keys. The DKIM core is delivered with feature 002. -->

## dkimKeyGeneration

Feature 010, written since feature 002. Written when a DKIM key is generated (`generateDkimKey`),
also as a follow-up step of a domain creation and of an alias domain creation (feature 004): the
key's history is complete even though the creation has its own record. The target is the key
(`type: dkimKey`, `id` the name, `name` its readable form). No private key material and no ISPConfig
password ever appear.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `selector` | string | The selector of the new key. | on success |
| `keyLength` | integer or null | Bits of the new key. | on success |
| `overwriteRequested` | boolean | Whether the request asked to replace an existing key (always `false` for creations). | on success |
| `old` | object | The replaced key: `selector`, `keyLength`, `publicKeySha256` (hex SHA-256 of the DER public key); `null` members for parts that were not stored. | when a key was replaced |
| `new` | object | The new key: `selector`, `keyLength`, `publicKeySha256`. | on success |
| `trigger` | string | What generated the key: `api`, `domainCreation` or `aliasDomainCreation`. | on success |
| `dnsPublication` | object | The FeldHost ISPConfig publication: `status` (`DkimDnsPublicationStatus`), `reasonCode` (`DkimDnsPublicationReasonCode` or `null`) and `detail` (technical text without secrets, or `null`). | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## dkimKeyImport

Feature 010. Written when a private key is imported (`importDkimKey`), and for every refused
attempt of an authenticated caller. The target is the key. The key text is never recorded, also not
in the `errors` of a refused import (rule codes and parameters only).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `selector` | string | The selector of the imported key. | on success |
| `keyLength` | integer or null | Bits of the imported key. | on success |
| `overwriteRequested` | boolean | Whether the request asked to replace an existing key. | on success |
| `old` | object | The replaced key: `selector`, `keyLength`, `publicKeySha256`. | when a key was replaced |
| `new` | object | The imported key: `selector`, `keyLength`, `publicKeySha256`. | on success |
| `dnsPublication` | object | The publication outcome, as for `dkimKeyGeneration`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## dkimKeyCopy

Feature 010. Written when a key is copied (`copyDkimKey`). The target is the key that was written
(the copy's target); the source is in `sourceName`. A refusal is recorded on the requested target.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `selector` | string | The selector copied from the source. | on success |
| `keyLength` | integer or null | Bits of the copied key. | on success |
| `overwriteRequested` | boolean | Whether the request asked to replace the target's key. | on success |
| `old` | object | The target's replaced key: `selector`, `keyLength`, `publicKeySha256`. | when a key was replaced |
| `new` | object | The copied key: `selector`, `keyLength`, `publicKeySha256`. | on success |
| `sourceName` | string | The source's name (its exact stored spelling for an unused legacy key). | on success |
| `dnsPublication` | object | The publication outcome for the target, as for `dkimKeyGeneration`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## dkimKeyDeletion

Feature 010. Written when a key is deleted (`deleteDkimKey`, or one item of
`applyDkimKeyBulkAction`). The deletion answers without a body, so this record is where clients read
the outcome of the record removal.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `old` | object | The deleted key: `selector`, `keyLength`, `publicKeySha256`. | on success |
| `dnsPublication` | object | The record removal: `status` (`removed`, `noRecordToRemove`, `noZone`, `failed` or `notConfigured`), `reasonCode`, `detail`. | on success |
| `bulkRequestId` | string | The request ID of the bulk deletion the item belongs to. | items of a bulk deletion |
| `targetIds` | array of strings | See "Refusal records" (bulk request refused as a whole). | bulk refusals |
| `bulkAction` | string | See "Refusal records" (always `delete`). | bulk refusals |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## dkimPrivateKeyExport

Feature 010. Written for every private key export (`exportDkimPrivateKey`) and every refused attempt
(a read, but a view of sensitive content). The key itself is never recorded.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `selector` | string | The selector of the exported key. | on success |
| `publicKeySha256` | string or null | Hex SHA-256 of the DER public key of the exported key. | on success |
| `problemType` | string | See "Refusal records" (for example `dkim-private-key-export-disabled`). | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## dkimDnsPublication

Feature 010. Written when an administrator publishes a key's record to ISPConfig again
(`publishDkimDnsRecord`). The key itself is unchanged.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `selector` | string | The selector whose record was published. | on success |
| `dnsPublication` | object | The publication outcome, as for `dkimKeyGeneration`. | on success |
| `problemType` | string | See "Refusal records" (for example `dkim-dns-publication-disabled`). | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mtaStsPolicyCreation

Feature 010. Written when an MTA-STS policy is created (`createMtaStsPolicy`), in the transaction of
its row. The target is the policy (`type: mtaStsPolicy`, `id` the domain).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `version` | string | `STSv1`. | on success |
| `mode` | string | `enforce`, `testing` or `none`. | on success |
| `mxHosts` | array of strings | The stored MX hosts, in order. | on success |
| `maxAge` | integer | The maximum age in seconds. | on success |
| `active` | boolean | Whether the policy is published. | on success |
| `policyId` | string | The 14-digit policy identifier. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mtaStsPolicyUpdate

Feature 010. Written when an MTA-STS policy is changed (`updateMtaStsPolicy`), in the transaction of
its row. A patch that changes nothing writes no record.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed member (`version`, `mode`, `mxHosts`, `maxAge`, `active`) with `{old, new}`. | on success |
| `oldPolicyId` | string | The policy identifier before the change. | on success |
| `newPolicyId` | string | The policy identifier after the change (equal to `oldPolicyId` when only `active` changed). | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

<!-- Feature 003: mailboxes and mailbox templates. -->

## mailboxCreation

Feature 003. Written for every creation of a mailbox (`POST /mailboxes`), in the transaction that
stores it, and for every refused creation. The target is the mailbox (`id` its address, `name` its
display name; a refused creation names the address as sent, normalised when possible, and no name).
The record is written with `creationReport: "pending"` and completed once after the follow-up steps
have run. The password and its hash never appear.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The stored values in contract names: `displayName`, `status`, `authSource`, `quota` (bytes), `forcePasswordChange`, `forceSecondFactorSetup`, `webmailRedirect`, `protocolAccess`, `userPermissions`, `tags`, `taggedMailHandling`, `quarantineNotification`, `quarantineCategory`, `tlsEnforceInbound`, `tlsEnforceOutbound` and the initial `rateLimit` (`{value, frame}` or `null`). | on success |
| `templateId` | integer or null | The mailbox template named in the request; `null` when none was named (the Default template and the built-in defaults applied). | on success |
| `passwordSet` | boolean | Whether a password was stored (`false` for an external authentication source). | on success |
| `preHashed` | boolean | Whether the password was given pre-hashed and stored as given. | on success, when `passwordSet` is `true` |
| `creationReport` | object | The creation report of the response (`MailboxCreationReport`): `rateLimit`, `taggedMailHandling`, `groupwareDirectoryUpdate` and, when a step failed, `messages`. `"pending"` only until the follow-up steps complete. | on success |
| `trigger` | string | Feature 012: `identityProviderSignIn` when a sign-in through the identity provider created the mailbox; the actor is then the signing-in user (`role` null). | for creations on sign-in |
| `template` | string | Feature 012: the name of the mapped or default template the mailbox was created from. | for creations on sign-in |
| `signInRequestId` | string | Feature 012: the request ID of the sign-in that created the mailbox (its `signIn` record carries it as `requestId`). | for creations on sign-in |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailboxUpdate

Feature 003. Written for every change of a mailbox (`PATCH /mailboxes/{mailbox}`) that changes at
least one stored value, for every such change of a bulk action (status or protocol), and for every
refusal of them. A request whose values equal the stored ones changes nothing and writes no record.
The target is the mailbox.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed value as `{old, new}`, keyed by its contract path: `displayName`, `status`, `quota` (bytes), `forcePasswordChange`, `forceSecondFactorSetup`, `webmailRedirect`, `authSource`, `recoveryEmail`, `tags` (sorted lists), `protocolAccess.<protocol>`, `userPermissions.<permission>` and `customAttributes.<name>` (`null` for an added or removed attribute). A new password appears only as `password: {passwordSet: true, preHashed}`, never its value or hash. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `trigger` | string | Feature 012: `identityProviderSignIn` for the update from the mapped template on sign-in (also its failures, which never stop the sign-in: a missing template is `failureReason: notFound`); the actor is the signing-in user (`role` null). | for updates on sign-in |
| `template` | string | Feature 012: the mapped template applied. | for updates on sign-in |
| `signInRequestId` | string | Feature 012: the request ID of the sign-in that caused the update. | for updates on sign-in |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailboxDeletion

Feature 003. Written for every deletion of a mailbox (`DELETE /mailboxes/{mailbox}` and bulk
deletions), in the transaction that deletes it, and for every refusal. The target is the deleted
mailbox. A deletion refused because the mail store failed, did not answer or was not asked records
`mailStoreCleanup`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removed` | object | Counts of what was removed with the mailbox: `exclusiveAliases` (aliases whose only recipient it was, its own alias not counted), `sharedAliases` (aliases that lost it as a recipient), and `senderPermissionsHeld`, `senderPermissionsGranted`, `temporaryAliases`, `syncJobs`, `appPasswords`, `sieveFilters`, `spamFilterSettings`, `quarantineItems`, `bccMaps`, `oauthGrants`, `secondFactors`, `passkeys`, `tags` and `recentLogins`. | on success |
| `mailStoreCleanup` | string | The move of the mail to the garbage area: `done`, `failed` (the mail store reported a failure or could not be reached), `unknown` (no answer within `DOCKER_TIMEOUT`) or `notAttempted` (a bulk deletion skipped the call after an earlier mailbox's mail store call timed out or found it unreachable). `done` on a failure record means the mail was moved but the records were kept. | on success; on failures after the preflight |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailboxRename

Feature 003. Written once per rename (`POST /mailboxes/{mailbox}/rename`) after its last step: for a
completed rename, for a rename that was undone (`503`, `failureReason: temporaryFailure`), for one
whose step did not answer (`504`, `outcomeUnknown`), for one that could neither complete nor be
undone (`500`, `partiallyCompleted`), and for every refusal before the first step. The target is the
mailbox under its previous address.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `previousAddress` | string | The address before the rename. | when the rename passed its checks |
| `newAddress` | string | The requested new address. | when the rename passed its checks |
| `keepOldAddressAsAlias` | boolean | Whether the old address was to be kept as an alias. | when the rename passed its checks |
| `steps` | array of objects | Every step in order with its outcome, as in `MailboxRenameStep` (`{step, outcome}`). | when the rename passed its checks |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailboxTemplateCreation

Feature 003. Written for every creation of a mailbox template (`POST /mailbox-templates`) and every
refusal. The target is the template (`type: mailboxTemplate`, `id` its ID, `name` its name; a refused
creation has no ID).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The template's values in contract names: `quota` (bytes), `status`, `forcePasswordChange`, `forceSecondFactorSetup`, `webmailRedirect`, `protocolAccess`, `userPermissions`, `tags`, `taggedMailHandling`, `quarantineNotification`, `quarantineCategory`, `tlsEnforceInbound`, `tlsEnforceOutbound` and `rateLimit` (`{value, frame}` or `null`). | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailboxTemplateUpdate

Feature 003. Written for every change of a mailbox template
(`PATCH /mailbox-templates/{mailboxTemplateId}`) that changes at least one stored value, and every
refusal. A request that changes nothing writes no record. The target carries the name after the
change.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed member (`name` and the values of `mailboxTemplateCreation`, nested members as dot paths such as `protocolAccess.pop3`) as `{old, new}`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailboxTemplateDeletion

Feature 003. Written for every deletion of a mailbox template
(`DELETE /mailbox-templates/{mailboxTemplateId}` and bulk deletion through
`POST /mailbox-templates/bulk-actions`) and every refusal. The target is the deleted template.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The values the template had, as in `mailboxTemplateCreation`. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

<!-- Feature 004: aliases, alias domains, resources and sender settings. -->

## aliasCreation

Feature 004. Written for every creation of an alias (`POST /aliases`), for every copy that a copy
over alias domains creates (`POST /aliases/{aliasId}/alias-domain-copies` and the bulk action
`copyOverAliasDomains`, one record per created copy), in the transaction that stores it, and for
every refusal. The target is the alias (`type: alias`, `id` its identifier, `name` its ASCII
address); a refused creation has no identifier, a refused copy names the copied alias.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The stored attributes in contract names: `address`, `destinationType`, `destinations` (as stored, empty for a special action), `active`, `senderAllowed`, `groupwareVisible`, `internal`, `publicComment` and `privateComment`. | on success |
| `groupwareUpdate` | string | The update of the destination mailboxes' groupware entries: `done`, `failed` (the alias was kept) or `groupwareDisabled`. | on success |
| `copiedFromAliasId` | integer | The identifier of the alias this copy was made from. | for copies |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for copies made by a bulk action |
| `targetIds` | array of strings | See "Refusal records". | when a whole `copyOverAliasDomains` bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## aliasUpdate

Feature 004. Written for every change of an alias (`PATCH /aliases/{aliasId}`), for every alias of
the bulk actions `activate`, `deactivate`, `showInGroupware` and `hideInGroupware`, and for every
refusal of them. A change whose values equal the stored ones is recorded too, with empty `changes`.
The target is the alias with its address after the change.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed attribute as `{old, new}`, keyed by its contract name: `address`, `destinationType`, `destinations` (lists), `active`, `senderAllowed`, `groupwareVisible`, `internal`, `publicComment`, `privateComment`. Empty when nothing changed. | on success |
| `groupwareUpdate` | string | The update of the former and new destination mailboxes' groupware entries: `done`, `failed` (the change was kept) or `groupwareDisabled`. Absent when nothing changed. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## aliasDeletion

Feature 004. Written for every deletion of an alias (`DELETE /aliases/{aliasId}` and bulk
deletions), in the transaction that deletes it, and for every refusal. The target is the deleted
alias.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removed` | object | What was removed with the alias: `senderPermissions`, the number of explicit sender permissions to send as its address (for a catch-all alias, the domain-wide permissions for its domain). | on success |
| `groupwareUpdate` | string | The update of the destination mailboxes' groupware entries: `done`, `failed` (the deletion was kept) or `groupwareDisabled`. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## aliasDomainCreation

Feature 004. Written for every creation of an alias domain (`POST /alias-domains`), in the
transaction that stores it with its domain map entry, and for every refusal. The target is the alias
domain (`type: aliasDomain`, `id` its ASCII name, `name` its readable name). The record is written
with `creationReport: "pending"` and completed once after the follow-up steps have run. A DKIM key
generated by the creation has its own `dkimKeyGeneration` record (`trigger: aliasDomainCreation`).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The stored values and requested follow-ups in contract names: `targetDomain`, `active`, `rateLimit` (`{value, frame}` or `null`), `dkimSelector` (empty: no key) and `dkimKeySize`, and `removedExternalSenderAddresses`, the number of external sender addresses removed because the name became local. | on success |
| `creationReport` | object | The creation report of the response (`AliasDomainCreationReport`): `rateLimit`, `dkimKey`, `dkimDnsPublication`, `groupwareDirectoryUpdate` and, when a step failed, `messages`. `"pending"` only until the follow-up steps complete. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## aliasDomainUpdate

Feature 004. Written for every change of an alias domain (`PATCH /alias-domains/{aliasDomain}`), for
every activation or deactivation of a bulk action, and for every refusal of them. A change whose
values equal the stored ones is recorded too, with empty `changes`. The target is the alias domain.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed attribute as `{old, new}`: `targetDomain` (ASCII), `active`. Empty when nothing changed. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## aliasDomainDeletion

Feature 004. Written for every deletion of an alias domain (`DELETE /alias-domains/{aliasDomain}`
and bulk deletions), in the transaction that deletes it, and for every refusal. The target is the
deleted alias domain. Its DKIM key is kept and is not recorded here.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removed` | object | What was removed with the alias domain: `aliases` (a list of `{id, address}` of the aliases that lived in it), and the counts `senderPermissions` (explicit permissions to send as those aliases or as the whole alias domain), `temporaryAliases`, `bccMaps` (its domain-level BCC maps) and `spamFilterSettings` (its spam allow and block list entries), and `footerExclusion` (boolean: whether its name was removed from the target domain's footer exclusions). | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## resourceCreation

Feature 004. Written for every creation of a resource (`POST /resources`), in the transaction that
stores it, and for every refusal. The target is the resource (`type: resource`, `id` its address,
`name` its description); a refused creation carries the address the description would derive,
when it derives one.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The stored attributes in contract names: `address`, `description`, `domain`, `kind`, `bookingRule`, `bookingLimit` and `active`. | on success |
| `groupwareUpdate` | string | The update of the resource's groupware entry: `done`, `failed` (the resource was kept) or `groupwareDisabled`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## resourceUpdate

Feature 004. Written for every change of a resource (`PATCH /resources/{resourceAddress}`), for every
activation or deactivation of a bulk action, and for every refusal of them. A change whose values
equal the stored ones is recorded too, with empty `changes`. The target is the resource with its
description after the change.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed attribute as `{old, new}`: `description`, `kind`, `bookingRule`, `bookingLimit`, `active`. Empty when nothing changed. | on success |
| `groupwareUpdate` | string | The update of the resource's groupware entry: `done`, `failed` (the change was kept) or `groupwareDisabled`. Absent when nothing changed. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## resourceDeletion

Feature 004. Written for every deletion of a resource (`DELETE /resources/{resourceAddress}` and bulk
deletions), in the transaction that deletes it, and for every refusal. The target is the deleted
resource.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removed` | object | Counts of the groupware data removed with the resource: `groupwareProfiles`, `groupwareFolders` (calendars and address books), `groupwareItems` (stored events and contacts), `groupwareSharingPermissions` and `groupwareCacheEntries`. | on success |
| `groupwareUpdate` | string | The removal of the resource's groupware entry: `done`, `failed` (the deletion was kept) or `groupwareDisabled`. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailboxSenderSettingsUpdate

Feature 004. Written for every change of a mailbox's sender settings
(`PATCH /mailboxes/{mailbox}/sender-settings`) and every refusal. A request whose lists equal the
stored ones is recorded too, with empty `changes`. The target is the sender settings
(`type: mailboxSenderSettings`, `id` and `name` the mailbox address).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | `explicitPermissions` and `externalSenderAddresses` as `{old, new}` (the stored lists, sorted), each when it changed. Empty when nothing changed. | on success |
| `groupwareUpdate` | string | The update of the mailbox's groupware entry after its external sender addresses changed: `done`, `failed` (the change was kept) or `groupwareDisabled`. | when the external sender addresses changed |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

<!-- Feature 011: monitoring and operations. -->

## containerStart

Feature 011. Written for every start of a container (`POST /containers/{containerName}/start`),
also when it was already running, and for every refusal. The target is the container
(`type: container`, `id` and `name` the service name as sent). A failure after a start was sent
(`failureReason: serviceFailure` or `outcomeUnknown`) is written by the operation itself, retried
until the container-service timeout; when it still cannot be written, the response carries
`Audit-Recorded: false`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `outcome` | string | `done`, or `alreadyInState` when nothing had to change. | on success |
| `stateBefore` | string | The container state before the action (`running`, `exited` and the other `ContainerState` values). | on success, on a `container-protected` refusal and on failures after an attempt |
| `stateAfter` | string | The container state read again after the action (on an unknown outcome: the last state read). | as `stateBefore` |
| `serviceMessage` | string | The container service's message, or `The container did not start.` | on `failureReason: serviceFailure` |
| `step` | string | The action whose outcome is unknown (`start`). | on `failureReason: outcomeUnknown` |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## containerStop

Feature 011. Written for every stop of a container (`POST /containers/{containerName}/stop`), also
when it was not running, and for every refusal, as `containerStart` (`serviceMessage` defaults to
`The container did not stop.`, `step` is `stop`).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `outcome` | string | `done` or `alreadyInState`. | on success |
| `stateBefore` | string | The container state before the action. | on success, on a `container-protected` refusal and on failures after an attempt |
| `stateAfter` | string | The container state after the action. | as `stateBefore` |
| `serviceMessage` | string | The container service's message. | on `failureReason: serviceFailure` |
| `step` | string | `stop`. | on `failureReason: outcomeUnknown` |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## containerRestart

Feature 011. Written for every restart of a container (`POST /containers/{containerName}/restart`)
and for every refusal, as `containerStart` (`step` is `restart`).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `outcome` | string | `done`. | on success |
| `stateBefore` | string | The container state before the restart. | on success, on a `container-protected` refusal and on failures after the restart was sent |
| `stateAfter` | string | The container state after the restart. | as `stateBefore` |
| `serviceMessage` | string | The container service's message. | on `failureReason: serviceFailure` |
| `step` | string | `restart`. | on `failureReason: outcomeUnknown` |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailQueueDelivery

Feature 011. Written once per `POST /mail-queue/messages/bulk-actions` with `action: deliver` (one
record for all its queue IDs, the spec's documented exception to one record per object), and for
every refusal of such a request. The target is `{type: mailQueue, id: null}`; the record's
`requestId` is the result's `bulkRequestId`. A Postfix failure or an unknown outcome is written by
the operation itself, with retries (`Audit-Recorded: false` when it still fails).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `results` | array of objects | Every distinct queue ID in request order with its result: `{id, outcome}`, `outcome` `done`, `notFound` (not in the queue when the request was processed) or `failed`. | on success and on `failureReason: serviceFailure` or `outcomeUnknown` |
| `serviceMessage` | string | Postfix's or the container service's message. | on `failureReason: serviceFailure` |
| `step` | string | `deliver`. | on `failureReason: outcomeUnknown` |
| `itemIds` | array of strings | The hexadecimal queue IDs the refused request named (at most 1,000). | on a refusal of the whole request |
| `bulkAction` | string or null | The action the refused request named, as sent (cut to 32 characters). | on a refusal of the whole request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailQueueHold

Feature 011. As `mailQueueDelivery`, for `action: hold` (`step` is `hold`).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `results` | array of objects | Every distinct queue ID with its result (`done`, `notFound`, `failed`). | on success and on failures after the command was sent |
| `serviceMessage` | string | Postfix's or the container service's message. | on `failureReason: serviceFailure` |
| `step` | string | `hold`. | on `failureReason: outcomeUnknown` |
| `itemIds` | array of strings | The hexadecimal queue IDs the refused request named. | on a refusal of the whole request |
| `bulkAction` | string or null | The action the refused request named. | on a refusal of the whole request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailQueueRelease

Feature 011. As `mailQueueDelivery`, for `action: release` (`step` is `release`).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `results` | array of objects | Every distinct queue ID with its result (`done`, `notFound`, `failed`). | on success and on failures after the command was sent |
| `serviceMessage` | string | Postfix's or the container service's message. | on `failureReason: serviceFailure` |
| `step` | string | `release`. | on `failureReason: outcomeUnknown` |
| `itemIds` | array of strings | The hexadecimal queue IDs the refused request named. | on a refusal of the whole request |
| `bulkAction` | string or null | The action the refused request named. | on a refusal of the whole request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailQueueDeletion

Feature 011. As `mailQueueDelivery`, for `action: delete` (`step` is `delete`). A refused request
whose action is missing or unknown is recorded under this type, with the sent action in
`bulkAction`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `results` | array of objects | Every distinct queue ID with its result (`done`, `notFound`, `failed`). | on success and on failures after the command was sent |
| `serviceMessage` | string | Postfix's or the container service's message. | on `failureReason: serviceFailure` |
| `step` | string | `delete`. | on `failureReason: outcomeUnknown` |
| `itemIds` | array of strings | The hexadecimal queue IDs the refused request named. | on a refusal of the whole request |
| `bulkAction` | string or null | The action the refused request named. | on a refusal of the whole request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailQueueFlush

Feature 011. Written for every `POST /mail-queue/flush` and every refusal. The target is
`{type: mailQueue, id: null}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `serviceMessage` | string | Postfix's or the container service's message. | on `failureReason: serviceFailure` |
| `step` | string | `flush`. | on `failureReason: outcomeUnknown` |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailQueuePurge

Feature 011. Written for every `POST /mail-queue/purge` (delete all) and every refusal, as
`mailQueueFlush` (`step` is `purge`).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `serviceMessage` | string | Postfix's or the container service's message. | on `failureReason: serviceFailure` |
| `step` | string | `purge`. | on `failureReason: outcomeUnknown` |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## mailQueueMessageView

Feature 011. Written for every view of a queued message's content
(`GET /mail-queue/messages/{queueId}/content`), the one audited read, also for a message that left
the queue (`failureReason: notFound`) and for refused views. The target is the queue ID
(`type: mailQueueMessage`). The content is never recorded; a view whose record cannot be written
returns no content.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## fail2banSettingsUpdate

Feature 011. Written for every change of the ban service settings (`PATCH /fail2ban/settings`) and
every refusal. The target is `{type: fail2banSettings, id: null}`. The ban list ID is never
recorded, also not when the first save generates it.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed parameter in contract names (`banTime`, `maxBanTime`, `banTimeIncrement`, `maxAttempts`, `retryWindow`, `ipv4PrefixLength`, `ipv6PrefixLength`, `externallyEnforced`) as `{old, new}`. Empty when no parameter changed. | on success |
| `allowlistAdded` | array of strings | Allowlist entries the change added. | when entries were added |
| `allowlistRemoved` | array of strings | Allowlist entries the change removed. | when entries were removed |
| `denylistAdded` | array of strings | Denylist entries the change added. | when entries were added |
| `denylistRemoved` | array of strings | Denylist entries the change removed. | when entries were removed |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records" (rule codes and parameters only, never the sent entries). | on `422` failures |

## fail2banRulesReplacement

Feature 011. Written for every replacement of the ban rules (`PUT /fail2ban/rules`) and every
refusal. The target is `{type: fail2banRules, id: null}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `oldRules` | array of strings | The rules before, in order (empty when none were stored). | on success |
| `newRules` | array of strings | The rules after, in order. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## fail2banRulesReset

Feature 011. Written for every reset of the ban rules (`POST /fail2ban/rules/reset`) and every
refusal. The target is `{type: fail2banRules, id: null}`. Outcomes after the rules were removed
(restored, `rulesNotRestored`, `serviceFailure`, `outcomeUnknown`) are written by the operation
itself, with retries.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `oldRules` | array of strings | The rules before the reset. | on success and on failures after the rules were removed |
| `newRules` | array of strings or null | The defaults the ban service stored, or `null` when they did not appear. | as `oldRules` |
| `serviceMessage` | string | The container service's message about the restart. | on `failureReason: serviceFailure` |
| `step` | string | `rulesReset`. | on `failureReason: outcomeUnknown` |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## fail2banBanLift

Feature 011. Written for every lift of an active ban
(`POST /fail2ban/active-bans/{network}/lift`) and every refusal. The target is the banned network
(`type: fail2banActiveBan`, `id` and `name` the network as stored, or as sent for a refusal).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `network` | string | The banned network as stored. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## fail2banBanAllowlisting

Feature 011. Written for every allowlisting of an actively banned network
(`POST /fail2ban/active-bans/{network}/allowlist`) and every refusal; target as
`fail2banBanLift`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `network` | string | The banned network as stored. | on success |
| `allowlistAdded` | array of strings | The network, when it was not on the allowlist yet. | when it was added |
| `denylistRemoved` | array of strings | The network, when it was on the denylist. | when it was removed |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## fail2banBanDenylisting

Feature 011. Written for every denylisting of an actively banned network
(`POST /fail2ban/active-bans/{network}/denylist`) and every refusal; target as `fail2banBanLift`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `network` | string | The banned network as stored. | on success |
| `allowlistRemoved` | array of strings | The network, when it was on the allowlist. | when it was removed |
| `denylistAdded` | array of strings | The network, when it was not on the denylist yet. | when it was added |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## fail2banBanListIdRotation

Feature 011. Written for every rotation of the ban list ID (`POST /fail2ban/ban-list-rotation`)
and every refusal. The target is `{type: fail2banBanListId, id: null}`. Neither the old nor the new
ID is recorded.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## syncJobCreation

Feature 008. Written for every creation of a sync job (`POST /sync-jobs`), in the transaction that
stores it, and for every refused creation. The target is the sync job (`type: syncJob`, `id` its
identifier, `name` its target mailbox); a refused creation has no identifier and names the mailbox
as sent (or the mailbox user's own address). The remote password never appears.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `mailbox` | string | The target mailbox: the stored address on success, the address as sent (at most 255 characters) on failure. | on success; on failure when a mailbox was sent or the caller is a mailbox user |
| `values` | object | The stored configuration in contract names: `mailbox`, `remoteHost`, `remotePort`, `encryption`, `remoteUser`, `exclude`, `destinationSubfolder`, `intervalMinutes`, `maxAgeDays`, `maxBytesPerSecond`, `remoteTimeoutSeconds`, `localTimeoutSeconds`, `deleteFromSource`, `deleteFromDestination`, `deleteDuplicatesOnDestination`, `automap`, `skipCrossDuplicates`, `subscribeAll`, `dryRun`, `customParameters`, `active`, and `remotePasswordSet` (never the password). | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## syncJobUpdate

Feature 008. Written for every change of a sync job (`PATCH /sync-jobs/{syncJobId}`, activation and
deactivation included), for every job of the bulk actions `activate` and `deactivate`, and for every
refusal of them. A change whose values equal the stored ones is recorded too, with empty `changes`.
The target is the sync job with its target mailbox.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `mailbox` | string | The job's target mailbox. | on success; on failure when the job exists |
| `changes` | object | Each changed member as `{old, new}`, keyed by its contract name (the members of `syncJobCreation`'s `values` but `remotePasswordSet`); a replaced remote password only as `remotePassword: "changed"`, never a value. Empty when nothing changed. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## syncJobReschedule

Feature 008. Written for every reschedule of a sync job (`POST /sync-jobs/{syncJobId}/schedule-reset`
and the bulk action `reschedule`), which clears the last run time and the last result, and for
every refusal. The target is the sync job with its target mailbox.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `mailbox` | string | The job's target mailbox. | on success; on failure when the job exists |
| `cleared` | array of strings | The cleared run-status members: always `["lastRunAt", "lastResult"]`. | on success |
| `previous` | object | Their values before the reschedule: `lastRunAt` (RFC 3339 UTC or `null`) and `lastResult` (`succeeded`, `failed` or `unknown`). | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## syncJobDeletion

Feature 008. Written for every deletion of a sync job (`DELETE /sync-jobs/{syncJobId}` and the bulk
action `delete`), in the transaction that deletes it, and for every refusal. The target is the
deleted sync job with its target mailbox.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `mailbox` | string | The job's target mailbox. | on success; on failure when the job exists |
| `remoteHost` | string | The deleted job's remote host. | on success |
| `remoteUser` | string | The deleted job's remote user. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

<!-- Feature 012: identity providers and OAuth2 clients. -->

## identityProviderCreation

Feature 012. Written when a whole save (`PUT /identity-provider`) created the configuration, in the
transaction that stores it, and for every refusal of a save while no configuration is stored. The
target is the configuration (`type: identityProvider`, `id` null, `name` the provider type, or the
requested type on a refusal). Secrets appear only as changed, never their values.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `type` | string | The saved provider type (`keycloak`, `genericOidc`, `ldap`). | on success |
| `changes` | object | Each stored setting, in contract names, as `{old, new}` (`old` is `null`); `clientSecret` and `bindPassword` as `{changed: true}`. | on success |
| `mailboxCounts` | object | The mailboxes per external source that blocked the save. | on `409 identity-provider-in-use` failures |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## identityProviderUpdate

Feature 012. Written for every whole save over a stored configuration (`PUT`) and every change
(`PATCH /identity-provider`), also when nothing changed, and for every refusal of them while a
configuration is stored. The target is the configuration (`name` the type after the save).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `type` | string | The provider type after the save. | on success |
| `previousType` | string | The provider type before the save; differs from `type` after a type change. | on success |
| `changes` | object | Each changed setting, in contract names, as `{old, new}`; settings of a previous type that were removed have `new: null`; `clientSecret` and `bindPassword` as `{changed: true}`. Empty when nothing changed. | on success |
| `mailboxCounts` | object | The mailboxes per external source that blocked a type change. | on `409 identity-provider-in-use` failures |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## identityProviderDeletion

Feature 012. Written when `DELETE /identity-provider` removed the configuration and for every
refusal. The target is the configuration (`name` the type it had).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `type` | string | The provider type that was removed. | on success |
| `mailboxCounts` | object | The mailboxes per external source (`keycloak`, `genericOidc`, `ldap`) that blocked the deletion. | on `409 identity-provider-in-use` failures |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## identityProviderConnectionTest

Feature 012. Written for every connection test (`POST /identity-provider/connection-tests`), with
`outcome: success` when the connection worked and `outcome: failure` with
`failureReason: connectionTestFailed` when it did not, and for every refusal of the request. The
target is the configuration (`name` the tested type). The secret is never recorded.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `type` | string | The tested provider type. | when the test ran |
| `testedAddress` | string | The token endpoint, or the LDAP connection URIs separated by spaces. | when the test ran |
| `outcome` | string | `success` or `failure`. | when the test ran |
| `failureCategory` | string or null | `unreachable`, `timedOut`, `tlsCertificateRejected`, `credentialsRejected` or `unexpectedResponse`; `null` on success. | when the test ran |
| `providerStatus` | integer or null | The HTTP status or LDAP result code of the provider's answer, `null` when none was received. | when the test ran |
| `problemType` | string | See "Refusal records". | on refusals of the request |
| `status` | integer | See "Refusal records". | on refusals of the request |
| `errors` | object | See "Refusal records". | on `422` refusals |

## identityProviderSessionEnd

Feature 012. Written when a single sign-on session was ended by the identity provider check of a
request (FR-026), with `outcome: failure`, `authMethod: singleSignOn` and a failure reason
(`providerRefused`, `identityProviderUnavailable`, `singleSignOnNotAvailable` or
`mailboxNotLinked`). The actor is the session's mailbox user, the target the session. Never contains
a provider token.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `identityProvider` | string | The provider type of the session. | always |
| `reason` | string | `providerRefused` (the renewal or the user details were refused), `providerUnavailable` (the provider could not be reached), `emailChanged` (the renewed details carry another e-mail address), `configurationChanged` (the configuration was removed, changed to another type or is incomplete) or `authSourceChanged` (the mailbox no longer authenticates against the provider). | always |

## oauth2ClientCreation

Feature 012. Written when `POST /oauth2-clients` created a client, in the transaction that stores
it, and for every refusal. The target is the client (`type: oauth2Client`, `id` its numeric
identifier, `name` its client ID; a refused creation has neither). The secret never appears.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `redirectUris` | array of strings | The stored redirect URIs, duplicates removed. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## oauth2ClientUpdate

Feature 012. Written for every change of a client's redirect URIs
(`PATCH /oauth2-clients/{oauth2ClientId}`), also when they did not change, and every refusal.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | `redirectUris` as `{old, new}` (lists) when they changed; empty otherwise. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## oauth2ClientSecretRegeneration

Feature 012. Written for every secret regeneration
(`POST /oauth2-clients/{oauth2ClientId}/secret-regeneration` and `renewSecret` bulk items, API gap
G42) and every refusal. Neither the old nor the new secret appears.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## oauth2ClientTokenRevocation

Feature 012. Written for every token revocation
(`POST /oauth2-clients/{oauth2ClientId}/token-revocation`), also when the client had no tokens, for
every item of a `revokeTokens` bulk action, and every refusal.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removed` | object | Rows removed: `accessTokens`, `refreshTokens`, `authorizationCodes`. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## oauth2ClientDeletion

Feature 012. Written for every deletion of a client (`DELETE /oauth2-clients/{oauth2ClientId}` and
`delete` bulk items), in the transaction that deletes it, and every refusal. The target carries the
client ID it had.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removed` | object | Rows removed with the client: `accessTokens`, `refreshTokens`, `authorizationCodes`. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## relayhostCreation

Feature 009. Written for every creation of a relayhost (`POST /relayhosts`), in the transaction
that stores it, and for every refusal. The target is the relayhost (`type: relayhost`, `id` its
identifier, `name` its host); a refused creation has no identifier and names the host as sent
(normalised when it is a valid next hop).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The stored attributes in contract names: `host`, `username` (`null` without one), `password` (`"set"` when a password was stored, `null` otherwise; never the password) and `active`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## relayhostUpdate

Feature 009. Written for every change of a relayhost (`PATCH /relayhosts/{relayhostId}`), for every
relayhost of the bulk actions `activate` and `deactivate`, and for every refusal. A change whose
values equal the stored ones is recorded too, with empty `changes`. The target is the relayhost
with its host after the change.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed attribute as `{old, new}`, keyed by its contract name: `host`, `username` (`null` for none), `active`; a changed password only as `password: "set"`, `"changed"` or `"removed"`. Empty when nothing changed. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## relayhostDeletion

Feature 009. Written for every deletion of a relayhost (`DELETE /relayhosts/{relayhostId}` and bulk
deletions), in the transaction that deletes it and clears its assignments, and for every refusal.
The target is the deleted relayhost.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `host` | string | The host the relayhost had. | on success |
| `clearedDomains` | array of strings | The domains (ASCII names, sorted) whose assignment to the relayhost was cleared. | on success |
| `clearedMailboxes` | array of strings | The mailboxes (addresses, sorted) whose assignment to the relayhost was cleared. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## relayhostTest

Feature 009. Written for every connection test of a relayhost
(`POST /relayhosts/{relayhostId}/connection-test`) that ran, whatever its outcome, in a transaction
of its own after the dialogue, and for every refusal. The target is the relayhost. The transcript
and the credentials are never recorded.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `sender` | string | The envelope sender of the test message (the request's or the server setting's default). | on success |
| `recipient` | string | The envelope recipient of the test message. | on success |
| `accepted` | boolean | Whether the next hop accepted the test message. | on success |
| `step` | string | The step reached or failed: `resolve`, `connect`, `greeting`, `tls`, `login`, `sender`, `recipient` or `message` (`RoutingConnectionTestStep`). | on success |
| `failureReason` | string or null | The reason of a failed test (`RoutingConnectionTestResult.failure.reason`, for example `timeout`, `loginFailed`, `rejected`), `null` when the message was accepted. Never the transcript. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## relayhostAssignmentUpdate

Feature 009. Written for every replacement of the relayhost assignment of a domain
(`PUT /domains/{domain}/relayhost-assignment`, target `type: domainRelayhostAssignment`, `id` and
`name` the ASCII domain name) or of a mailbox (`PUT /mailboxes/{mailbox}/relayhost-assignment`,
target `type: mailboxRelayhostAssignment`, `id` and `name` the mailbox address), and for every
refusal, including a domain administrator's attempt without the permission flag. A replacement with
the stored value is recorded too, with empty `changes`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | `relayhostId` as `{old, new}` (integers, `null` for none; a dangling old reference keeps its stored identifier), when it changed. Empty otherwise. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## transportCreation

Feature 009. Written for every creation of a transport (`POST /transports`), in the transaction that
stores it and propagates the credentials of its next hop, and for every refusal. The target is the
transport (`type: transport`, `id` its identifier, `name` its destination).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The stored attributes in contract names: `destination`, `nextHop`, `mxBased`, `active`, `username` (`null` without one) and `password` (`"set"` when one is stored, `null` otherwise; never the password). | on success |
| `affectedTransportIds` | array of integers | The other transports of the next hop whose stored credentials the creation changed, sorted. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## transportUpdate

Feature 009. Written for every change of a transport (`PATCH /transports/{transportId}`), for every
transport of the bulk actions `activate` and `deactivate`, and for every refusal. A change whose
values equal the stored ones is recorded too, with empty `changes`. The target is the transport with
its destination after the change.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed attribute as `{old, new}`, keyed by its contract name: `destination`, `nextHop`, `mxBased`, `active`, `username` (`null` for none); a changed password only as `password: "set"`, `"changed"` or `"removed"`. Empty when nothing changed. | on success |
| `affectedTransportIds` | array of integers | The other transports of the resulting next hop whose stored credentials the change changed, sorted. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## transportDeletion

Feature 009. Written for every deletion of a transport (`DELETE /transports/{transportId}` and bulk
deletions), in the transaction that deletes it, and for every refusal. The target is the deleted
transport.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `destination` | string | The destination the transport had. | on success |
| `nextHop` | string | The next hop the transport had. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## transportTest

Feature 009. Written for every connection test of a transport
(`POST /transports/{transportId}/connection-test`) that ran, whatever its outcome, and for every
refusal. The target is the transport. The transcript and the credentials are never recorded.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `sender` | string | The envelope sender of the test message (the request's or the server setting's default). | on success |
| `recipient` | string | The envelope recipient of the test message. | on success |
| `accepted` | boolean | Whether the next hop accepted the test message. | on success |
| `step` | string | The step reached or failed: `resolve`, `connect`, `greeting`, `tls`, `login`, `sender`, `recipient` or `message` (`RoutingConnectionTestStep`). | on success |
| `failureReason` | string or null | The reason of a failed test (`RoutingConnectionTestResult.failure.reason`, for example `timeout`, `loginFailed`, `rejected`), `null` when the message was accepted. Never the transcript. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## bccMapCreation

Feature 009. Written for every creation of a BCC map (`POST /bcc-maps`), in the transaction that
stores it, and for every refusal. The target is the map (`type: bccMap`, `id` its identifier,
`name` its local destination).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The stored attributes in contract names: `localDestination`, `type` (`sender` or `recipient`), `bccDestination`, `domain` and `active`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## bccMapUpdate

Feature 009. Written for every change of a BCC map (`PATCH /bcc-maps/{bccMapId}`), for every map of
the bulk actions `activate`, `deactivate`, `switchToSender` and `switchToRecipient`, and for every
refusal. A change whose values equal the stored ones is recorded too, with empty `changes`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed attribute as `{old, new}`: `type`, `bccDestination`, `active`. Empty when nothing changed. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## bccMapDeletion

Feature 009. Written for every deletion of a BCC map (`DELETE /bcc-maps/{bccMapId}` and bulk
deletions) and every refusal. The target is the deleted map.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `localDestination` | string | The local destination the map had. | on success |
| `type` | string | The map's type: `sender` or `recipient`. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## recipientMapCreation

Feature 009. Written for every creation of a recipient map (`POST /recipient-maps`) and every
refusal. The target is the map (`type: recipientMap`, `id` its identifier, `name` its original
destination).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The stored attributes in contract names: `originalDestination`, `newDestination` and `active`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## recipientMapUpdate

Feature 009. Written for every change of a recipient map (`PATCH /recipient-maps/{recipientMapId}`),
for every map of the bulk actions `activate` and `deactivate`, and for every refusal. A change whose
values equal the stored ones is recorded too, with empty `changes`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed attribute as `{old, new}`: `originalDestination`, `newDestination`, `active`. Empty when nothing changed. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## recipientMapDeletion

Feature 009. Written for every deletion of a recipient map and every refusal. The target is the
deleted map.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `originalDestination` | string | The original destination the map had. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## tlsPolicyMapCreation

Feature 009. Written for every creation of a TLS policy map (`POST /tls-policy-maps`) and every
refusal. The target is the entry (`type: tlsPolicyMap`, `id` its identifier, `name` its destination).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `values` | object | The stored attributes in contract names: `destination`, `policy`, `parameters` (`""` for none) and `active`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## tlsPolicyMapUpdate

Feature 009. Written for every change of a TLS policy map (`PATCH /tls-policy-maps/{tlsPolicyMapId}`),
for every entry of the bulk actions `activate` and `deactivate`, and for every refusal. A change
whose values equal the stored ones is recorded too, with empty `changes`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed attribute as `{old, new}`: `destination`, `policy`, `parameters`, `active`. Empty when nothing changed. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## tlsPolicyMapDeletion

Feature 009. Written for every deletion of a TLS policy map and every refusal. The target is the
deleted entry.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `destination` | string | The destination the entry had. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## forwardingHostCreation

Feature 009. Written for every addition of a forwarding host (`POST /forwarding-hosts`), in the
transaction whose last step writes mailcow's Redis, and for every refusal. One record covers every
address the request added or updated. The target is `type: forwardingHost` with `id` the source
(the trimmed host as entered) and `name` the stored addresses joined by spaces (cut to 255
characters), so that the `q` search of the audit log finds the record by an address.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `entries` | array of objects | Every address of the request as `{address, outcome}`: the stored address and `added` or `updated`. | on success |
| `spamFilter` | boolean | The spam filter setting written for every entry. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## forwardingHostUpdate

Feature 009. Written for every change of a forwarding host's spam filter
(`PATCH /forwarding-hosts/{forwardingHostAddress}`), for every entry of the bulk actions
`enableSpamFilter` and `disableSpamFilter`, and for every refusal. A change that keeps the stored
setting is recorded too, with empty `changes`. The target is the entry (`id` and `name` its stored
address).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | `spamFilter` as `{old, new}`, when it changed. Empty otherwise. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

## forwardingHostDeletion

Feature 009. Written for every deletion of a forwarding host and every refusal. The target is the
deleted entry (`id` and `name` its stored address).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `source` | string | The source the entry had. | on success |
| `bulkRequestId` | string | The request ID of the bulk action the record belongs to. | for bulk items |
| `targetIds` | array of strings | See "Refusal records". | when a whole bulk request was refused |
| `bulkAction` | string or null | The requested bulk action. | when a whole bulk request was refused |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | The permission flags the caller lacks (names of `PermissionFlags`), as in the `permission-flag-required` problem. | on `permission-flag-required` failures |

<!-- Feature 005: account self-service. -->

## Refusal records of feature 005

The state-changing operations of feature 005 declare refusal auditing like those of feature 002
onwards ("Refusal records" above), with the same keys plus `requiredFlags` (array of strings: the
permission flags a refused change needed, named as in `PermissionFlags`, on
`permission-flag-required` refusals). The target of a mailbox object (app password, temporary
alias, Sieve filter, push notification settings, mail settings, recent logins, groupware data) is
recorded with `id` = the mailbox address, so that `targetId=<mailbox>` lists every record of the
mailbox, and `name` = the object's label when the path names one; the object's own identifier is in
`details`. Own-account targets (account password, second factor, recovery address, webmail hand-off)
are the signed-in account (`id` = its username). Feature 005 maps its own problem types to the
failure reasons `currentPasswordIncorrect`, `externalIdentitySource`, `featureDisabled`,
`challengeExpired`, `registrationFailed`, `lastSecondFactor`, `secondFactorRequiredByPolicy`,
`notConfigured` and `verificationUnavailable`. Secrets (passwords, codes, Yubico credentials, reset
tokens, app passwords, Pushover credentials) never appear in any record.

## ownPasswordChange

Feature 005. Written for every change of the signed-in account's own password
(`PUT /me/password`) and every refusal, including a wrong current password (`failureReason:
currentPasswordIncorrect`, which also counts towards the sign-in throttling). The target is
`{type: accountPassword, id: <username>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `password` | string | Always `changed`; the password itself is never recorded. | on success |
| `forcedChangeResolved` | boolean | Whether the change resolved a forced password change (`force_pw_update`). | on success |
| `sessionsEnded` | integer | The account's other sessions of this API that the change ended. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## ownSecondFactorEnrolment

Feature 005. Written for every enrolment of an own second factor (`POST /me/second-factors`) and
every refusal: a wrong current password, an expired or used-up enrolment (`challengeExpired`), a
rejected code, response or OTP (`registrationFailed`), an unreachable Yubico service
(`verificationUnavailable`). Starting a TOTP enrolment and requesting WebAuthn options are not
recorded. The target is `{type: secondFactor, id: <username>, name: <entry name>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `secondFactorId` | integer | The `tfa.id` of the new entry. | on success |
| `secondFactorType` | string | `totp`, `webauthn` or `yubiOtp`. | always |
| `replacedIds` | array of integers | Yubico OTP: the entries of the same key that the enrolment replaced. | on success of a Yubico OTP enrolment |
| `restrictionLifted` | boolean | Whether the enrolment resolved a forced second-factor setup. | on success |
| `attemptsRemaining` | integer | TOTP: the wrong codes left before the enrolment is cancelled. | on a rejected TOTP code |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## ownSecondFactorRemoval

Feature 005. Written for every removal of one own second factor
(`DELETE /me/second-factors/{secondFactorId}`) or of all of them (`POST /me/second-factors/removal`)
and every refusal (`lastSecondFactor`, `secondFactorRequiredByPolicy`, `currentPasswordIncorrect`).
The target is `{type: secondFactor, id: <username>, name: <entry name>}` (no name when all are
removed).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `secondFactorId` | integer | The removed entry. | when one entry was removed or refused |
| `secondFactorType` | string | Its type: `totp`, `webauthn`, `yubiOtp`, `u2f` or `hotp`. | when one entry was removed |
| `removedIds` | array of integers | The entries removed by "remove all". | on success of "remove all" |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## ownRecoveryEmailChange

Feature 005. Written for every replacement of the signed-in mailbox user's recovery address
(`PUT /me/recovery-email`) and every refusal. A replacement with the stored value is recorded with
empty `changes`. The target is `{type: recoveryEmail, id: <mailbox>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | `recoveryEmail` as `{old, new}` (empty string when unset), when it changed. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## passwordResetRequest

Feature 005. Written by the service for every well-formed reset request (`POST /password-resets`),
after the acknowledgement was sent. There is no actor. The target is
`{type: passwordReset, id: <attempted address>}`. A sent message is a success; otherwise
`failureReason` is `unknownAccount`, `accountNotEligible` (inactive, resource or external-source
mailbox), `noRecoveryEmail`, `tokenLimitReached` or `messageNotSent`. The token is never recorded.
No keys.

## passwordResetCompletion

Feature 005. Written by the service for every completion attempt with a valid token
(`POST /password-resets/completion`) that reached the second-factor check or the change: success, or
failure with `secondFactorFailed`, `secondFactorUnavailable` or `verificationUnavailable`. There is
no actor. The target is `{type: passwordReset, id: <mailbox>}`. The token and the password are never
recorded.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `password` | string | Always `changed`. | on success |
| `forcedChangeResolved` | boolean | Whether a forced password change was pending and is now cleared. | on success |
| `secondFactorType` | string | The second factor used or attempted: `totp`, `webauthn` or `yubiOtp`. | when the account has second factors |
| `sessionsEnded` | integer | The account's sessions of this API that the reset ended. | on success |

## appPasswordCreation

Feature 005. Written for every creation of an app password (`POST /mailboxes/{mailbox}/app-passwords`,
and `POST /me/mailbox/apple-profile` with an embedded app password) and every refusal. The target is
`{type: appPassword, id: <mailbox>, name: <app password name>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `appPasswordId` | integer | The new app password's ID. | on success |
| `values` | object | The stored `name` (plain text), `protocols` (array) and `active`. | on success |
| `passwordGenerated` | boolean | Whether the system generated the password; the password itself is never recorded. | on success |
| `source` | string | `appleProfile` when created for an Apple connection profile. | when created for an Apple profile |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## appPasswordUpdate

Feature 005. Written for every change of an app password
(`PATCH /mailboxes/{mailbox}/app-passwords/{appPasswordId}`) and every refusal. A change whose values
equal the stored ones is recorded with empty `changes`. The target is
`{type: appPassword, id: <mailbox>, name: <app password name>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `appPasswordId` | integer | The app password's ID. | on success, and on refusals that name an existing one |
| `changes` | object | `name`, `protocols` and `active` as `{old, new}`, each when it changed; `password` as `"changed"` (or `"generated"`) when a new password was set. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## appPasswordDeletion

Feature 005. Written for every deletion of an app password and every refusal. The target is
`{type: appPassword, id: <mailbox>, name: <app password name>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `appPasswordId` | integer | The app password's ID. | on success, and on refusals that name an existing one |
| `removed` | object | The removed app password's `name`, `protocols` and `active`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## temporaryAliasCreation

Feature 005. Written for every creation of a temporary alias
(`POST /mailboxes/{mailbox}/temporary-aliases`) and every refusal. The target is
`{type: temporaryAlias, id: <mailbox>, name: <temporary alias address>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `temporaryAlias` | string | The generated address. | on success |
| `values` | object | The stored `description`, `permanent` and `expiresAt` (RFC 3339, UTC, or `null` when permanent). | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## temporaryAliasUpdate

Feature 005. Written for every change of a temporary alias's lifetime
(`PATCH /mailboxes/{mailbox}/temporary-aliases/{temporaryAlias}`) and every refusal. The target is
`{type: temporaryAlias, id: <mailbox>, name: <temporary alias address>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `temporaryAlias` | string | The temporary alias's address. | on success |
| `changes` | object | `permanent` and `expiresAt` as `{old, new}`, each when it changed. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## temporaryAliasDeletion

Feature 005. Written for every deletion of a temporary alias and every refusal. The target is
`{type: temporaryAlias, id: <mailbox>, name: <temporary alias address>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `temporaryAlias` | string | The removed address. | on success |
| `removed` | object | Its `description`, `permanent` and `expiresAt`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## sieveFilterCreation

Feature 005. Written for every creation of a Sieve filter (`POST /mailboxes/{mailbox}/sieve-filters`)
and every refusal. The target is `{type: sieveFilter, id: <mailbox>, name: <description>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `sieveFilterId` | integer | The new filter's ID. | on success |
| `values` | object | `description`, `kind`, `active`, and `script` as `{size}` (bytes); the script text is never recorded. | on success |
| `deactivatedIds` | array of integers | Filters of the same kind that the activation deactivated. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## sieveFilterUpdate

Feature 005. Written for every change of a Sieve filter
(`PATCH /mailboxes/{mailbox}/sieve-filters/{sieveFilterId}`) and every refusal. A change whose values
equal the stored ones is recorded with empty `changes`. The target is
`{type: sieveFilter, id: <mailbox>, name: <description>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `sieveFilterId` | integer | The filter's ID. | on success, and on refusals that name an existing one |
| `changes` | object | `description`, `kind` and `active` as `{old, new}`, each when it changed; `script` as `{changed: true, oldSize, newSize}` when it changed. | on success |
| `deactivatedIds` | array of integers | Filters of the same kind that the change deactivated. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## sieveFilterDeletion

Feature 005. Written for every deletion of a Sieve filter and every refusal. The target is
`{type: sieveFilter, id: <mailbox>, name: <description>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `sieveFilterId` | integer | The filter's ID. | on success, and on refusals that name an existing one |
| `removed` | object | Its `description`, `kind`, `active` and `script` as `{size}`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## pushNotificationSettingsUpdate

Feature 005. Written for every replacement of a mailbox's push notification settings
(`PUT /mailboxes/{mailbox}/push-notifications`) and every refusal. A replacement with the stored
values is recorded with empty `changes`. The target is
`{type: pushNotificationSettings, id: <mailbox>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | `active`, `senders`, `senderPattern`, `sound`, `evaluatePriority` and `onlyHighPriority` as `{old, new}`, each when it changed; `title` and `text` as `{changed: true, oldSize, newSize}`; `token` and `userKey` as `"set"` (first save) or `"changed"`. | on success |
| `created` | boolean | Whether the mailbox had no settings before. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## pushNotificationSettingsDeletion

Feature 005. Written for every removal of a mailbox's push notification settings
(`DELETE /mailboxes/{mailbox}/push-notifications`) and every refusal. The target is
`{type: pushNotificationSettings, id: <mailbox>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removed` | boolean | Whether settings existed and were removed. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## pushNotificationVerification

Feature 005. Written for every verification of the stored Pushover credentials
(`POST /mailboxes/{mailbox}/push-notifications/verification`) and every refusal
(`notConfigured`, `temporaryFailure` when Pushover is unreachable). The target is
`{type: pushNotificationSettings, id: <mailbox>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `accepted` | boolean | Pushover's verdict on the credentials. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## mailSettingsUpdate

Feature 005. Written for every change of a mailbox's mail settings
(`PATCH /mailboxes/{mailbox}/mail-settings`), for every item of a bulk TLS change
(`POST /mailboxes/mail-settings/bulk-actions`) and every refusal. A change with the stored values is
recorded with empty `changes`. The target is `{type: mailSettings, id: <mailbox>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | `taggedMailHandling`, `tlsEnforceInbound` and `tlsEnforceOutbound` as `{old, new}`, each when it changed. | on success |
| `bulkRequestId` | string | The bulk request the item belongs to. | on records of bulk items |
| `targetIds` | array of strings | See "Refusal records". | on a refused bulk request |
| `bulkAction` | string | See "Refusal records". | on a refused bulk request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## recentLoginsClear

Feature 005. Written for every clearing of a mailbox's recent logins
(`DELETE /mailboxes/{mailbox}/recent-logins`) and every refusal. The target is
`{type: recentLogins, id: <mailbox>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removedRecentLogins` | integer | The removed recent-login records (`sasl_log`). | on success |
| `removedSignInRecords` | integer | The removed legacy UI sign-in records of the mailbox (`logs`); 0 when the caller is a domain administrator, who never removes them. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## activeSyncCacheReset

Feature 005. Written for every reset of a mailbox's ActiveSync cache
(`DELETE /mailboxes/{mailbox}/activesync-cache`) and every refusal. The target is
`{type: groupwareData, id: <mailbox>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removed` | object | The removed rows per groupware table (`sogo_cache_folder`). | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## groupwareProfileReset

Feature 005. Written for every reset of a mailbox's groupware profile
(`DELETE /mailboxes/{mailbox}/groupware-profile`) and every refusal. The target is
`{type: groupwareData, id: <mailbox>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `removed` | object | The removed rows per groupware table (`sogo_user_profile`, `sogo_cache_folder`, `sogo_acl`, `sogo_store`, `sogo_quick_contact`, `sogo_quick_appointment`, `sogo_folder_info`). | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

## webmailHandOff

Feature 005. Written for every webmail hand-off (`POST /me/webmail-handoff`) and every refusal. The
target is `{type: webmailHandOff, id: <mailbox>}`. The legacy session identifier is never recorded.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 005". | on `permission-flag-required` failures |

<!-- Feature 007: administrators, domain administrators, single sign-on tokens and impersonation. -->

## Refusal records of feature 007

The state-changing operations of feature 007 declare refusal auditing like those of feature 002
onwards ("Refusal records" above): every refusal of an authenticated caller writes one record with
`problemType`, `status` and, for `422`, `errors`, including refusals of read-only keys
(`insufficient-key-scope`), of keys on session-only operations (`session-required`), of restricted
sessions and of out-of-scope or unknown accounts (`404`). The target of an account operation is the
account as addressed (`{type: admin | domainAdmin, id: <username as sent, lower-cased>, name: same}`),
or `null` for a creation whose username is invalid. Impersonation and webmail refusals also record
`requiredFlags` (`["loginAs"]`) on `permission-flag-required`. A single sign-on token issued for an
inactive account is recorded with `failureReason: accountInactive`; the other feature 007 problem
types map to `conflict` (`409`) and `permissionDenied` (`403`). Records written during an
impersonation, by any feature, carry the impersonator as `actor` and the target as
`impersonatedAccount`. Passwords, password hashes and single sign-on tokens never appear in any
record.

## adminCreation

Feature 007. Written for every creation of an administrator (`POST /admins`) and every refusal.
The target is `{type: admin, id: <username>, name: <username>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `active` | boolean | The status of the new account. | on success |
| `forcePasswordChange` | boolean | Whether the account must change its password at its next sign-in. | on success |
| `forceSecondFactorSetup` | boolean | Whether the account must set up a second factor at its next sign-in. | on success |
| `passwordPreHashed` | boolean | Whether the password was given with a hash-scheme prefix and stored as given. The password is never recorded. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## adminUpdate

Feature 007. Written for every change of an administrator (`PATCH /admins/{username}`, and the
`activate` and `deactivate` bulk actions) that changes a stored value, and every refusal. A patch
that changes nothing writes no record. The target is the account under its username before the
change; a rename is recorded in `changes.username`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | The changed values as `{field: {old, new}}`: `username`, `active`, `forcePasswordChange`, `forceSecondFactorSetup`. | on success |
| `passwordChanged` | boolean | `true` when the password was changed; the password is never recorded. | on success, when the password changed |
| `passwordPreHashed` | boolean | Whether the new password was given pre-hashed. | on success, when the password changed |
| `bulkRequestId` | string | The request ID of the bulk action the change belongs to. | for items of a bulk action |
| `targetIds` | array of strings | See "Refusal records" (a bulk request refused as a whole). | on a refused bulk request |
| `bulkAction` | string | The requested bulk action (`activate`, `deactivate`). | on a refused bulk request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## adminDeletion

Feature 007. Written for every deletion of an administrator (`DELETE /admins/{username}` and the
`delete` bulk action) and every refusal, including `cannot-delete-own-account` and
`last-active-admin`. The target is the deleted account.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `revokedApiKeyIds` | array of strings | The API keys of the account that the deletion revoked (`revocationReason: ownerDeleted`). | on success |
| `removedSecondFactors` | integer | The number of `tfa` entries (TOTP, WebAuthn, Yubico OTP and deprecated types) removed. | on success |
| `removedPasskeys` | integer | The number of passkeys removed. | on success |
| `bulkRequestId` | string | See `adminUpdate`. | for items of a bulk action |
| `targetIds` | array of strings | See "Refusal records". | on a refused bulk request |
| `bulkAction` | string | `delete`. | on a refused bulk request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## adminSecondFactorSuspension

Feature 007. Written for every second-factor suspension of an administrator
(`POST /admins/{username}/second-factor-suspension` and the `suspendSecondFactors` bulk action)
and every refusal, also when no entry was active.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `suspendedCount` | integer | The number of second-factor entries that were active and were suspended (every entry type, as legacy's `disable_tfa`; 0 when none was active). | on success |
| `bulkRequestId` | string | See `adminUpdate`. | for items of a bulk action |
| `targetIds` | array of strings | See "Refusal records". | on a refused bulk request |
| `bulkAction` | string | `suspendSecondFactors`. | on a refused bulk request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainAdminCreation

Feature 007. Written for every creation of a domain administrator (`POST /domain-admins`) and
every refusal. The target is `{type: domainAdmin, id: <username>, name: <username>}`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `active` | boolean | The status of the new account and its assignments. | on success |
| `forcePasswordChange` | boolean | See `adminCreation`. | on success |
| `forceSecondFactorSetup` | boolean | See `adminCreation`. | on success |
| `passwordPreHashed` | boolean | See `adminCreation`. | on success |
| `domains` | array of strings | The assigned domains (ASCII form), sorted. | on success |
| `permissions` | object | The 19 permission flags as stored (`{flag: boolean}`, contract names). | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainAdminUpdate

Feature 007. Written for every change of a domain administrator (`PATCH /domain-admins/{username}`,
and the `activate` and `deactivate` bulk actions) that changes a stored value, and every refusal.
A patch that changes nothing writes no record. The target is the account under its username before
the change.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | See `adminUpdate`. | on success |
| `passwordChanged` | boolean | See `adminUpdate`. | on success, when the password changed |
| `passwordPreHashed` | boolean | See `adminUpdate`. | on success, when the password changed |
| `domainsAdded` | array of strings | Domains assigned by the change (ASCII form), sorted. | on success, when the domain set changed |
| `domainsRemoved` | array of strings | Assignments removed by the change, sorted; names that are not primary domains (for example legacy's `ALL`) included. | on success, when the domain set changed |
| `permissionsChanged` | object | The changed permission flags as `{flag: {old, new}}` (contract names; `old` is `false` when the account had no permission record). | on success, when a flag changed |
| `bulkRequestId` | string | See `adminUpdate`. | for items of a bulk action |
| `targetIds` | array of strings | See "Refusal records". | on a refused bulk request |
| `bulkAction` | string | `activate` or `deactivate`. | on a refused bulk request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainAdminDeletion

Feature 007. Written for every deletion of a domain administrator (`DELETE /domain-admins/{username}`,
the `delete` bulk action, and feature 002's domain deletion that leaves the account without a
domain) and every refusal. The target is the deleted account.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `revokedApiKeyIds` | array of strings | See `adminDeletion`. | on success |
| `removedSecondFactors` | integer | See `adminDeletion`. | on success |
| `removedPasskeys` | integer | See `adminDeletion`. | on success |
| `domains` | array of strings | The assignments the account had, sorted. | on success |
| `removedSsoTokens` | integer | The number of unused single sign-on tokens removed. | on success |
| `trigger` | string | `request` for a deletion of the account, `domainDeletion` when feature 002's domain deletion removed its last domain (the record then carries the domain deletion's request ID). | on success |
| `bulkRequestId` | string | See `adminUpdate`. | for items of a bulk action |
| `targetIds` | array of strings | See "Refusal records". | on a refused bulk request |
| `bulkAction` | string | `delete`. | on a refused bulk request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainAdminSecondFactorSuspension

Feature 007. As `adminSecondFactorSuspension`, for a domain administrator
(`POST /domain-admins/{username}/second-factor-suspension` and the `suspendSecondFactors` bulk
action).

| Key | Type | Meaning | Present |
|---|---|---|---|
| `suspendedCount` | integer | See `adminSecondFactorSuspension`. | on success |
| `bulkRequestId` | string | See `adminUpdate`. | for items of a bulk action |
| `targetIds` | array of strings | See "Refusal records". | on a refused bulk request |
| `bulkAction` | string | `suspendSecondFactors`. | on a refused bulk request |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## ssoTokenIssue

Feature 007. Written for every single sign-on token issued for a domain administrator
(`POST /domain-admins/{username}/sso-token`) and every refusal (an inactive account:
`failureReason: accountInactive`). The target is `{type: ssoToken, id: null, name: <username>}`.
The token itself is never recorded. Signing in with a token is a `signIn` record with
`authMethod: ssoToken` and `step: ssoToken` on failure.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `expiresAt` | string (RFC 3339, UTC) | When the token expires (issue time plus 30 seconds). | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## impersonationStart

Feature 007. Written for every start of an impersonation (`POST /session/impersonation`) and every
refusal of an authenticated caller. The target is `{type: impersonation, id: <impersonation ULID>,
name: <target username>}` on success, and `{type: impersonation, id: null, name: <requested
username>}` on failure. The record of a start carries the target as `impersonatedAccount`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `targetRole` | string | `mailboxUser` or `domainAdmin`. | on success, and on failure when the request named a valid role |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 007". | on `permission-flag-required` failures |

## impersonationEnd

Feature 007. Written when an impersonation ends: explicitly (`DELETE /session/impersonation`), by
signing out (`DELETE /session`, before the `signOut` record), automatically when it became invalid,
or when a new sign-in replaces the impersonating session. Ending without an impersonation writes
nothing. The target is the impersonation (`id` its ULID, `name` the target username), and the record
carries the target as `impersonatedAccount` and the impersonator as `actor`.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `endedBy` | string | `explicit`, `signOut`, `automatic` or `sessionReplaced`. | on success |
| `endReason` | string | Why it ended automatically: `targetDeletedOrRenamed`, `targetOutOfScope` or `loginAsRevoked`. | when `endedBy` is `automatic` |
| `durationSeconds` | integer | Seconds since the impersonation started. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## webmailSignInOnBehalf

Feature 007. Written for every webmail sign-in on behalf of a mailbox user
(`POST /mailboxes/{mailbox}/webmail-sign-in`) and every refusal of an authenticated caller. The
target is `{type: mailbox, id: <mailbox address as normalised>, name: null}`. The legacy session
identifier is never recorded.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |
| `requiredFlags` | array of strings | See "Refusal records of feature 007". | on `permission-flag-required` failures |
