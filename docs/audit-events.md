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
