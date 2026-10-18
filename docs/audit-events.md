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
| `values` | object | The template's values in contract names: `tags`, `maxMailboxes`, `maxAliases`, `defaultMailboxQuota`, `maxMailboxQuota`, `domainQuota` (bytes), `rateLimit` (`{value, frame}` or `null`), `active`, `globalAddressList`, `backupMx`, `relayAllRecipients`, `relayUnknownOnly`, `dkimSelector` and `dkimKeySize`. | on success |
| `problemType` | string | See "Refusal records". | on failure |
| `status` | integer | See "Refusal records". | on failure |
| `errors` | object | See "Refusal records". | on `422` failures |

## domainTemplateUpdate

Feature 002. Written for every edit of a domain template (`PATCH /domain-templates/{templateId}`),
also when nothing changes, and every refusal. The target carries the name after the edit.

| Key | Type | Meaning | Present |
|---|---|---|---|
| `changes` | object | Each changed member (`name` and the values of `domainTemplateCreation`) as `{old, new}`. Empty when nothing changed. | on success |
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
