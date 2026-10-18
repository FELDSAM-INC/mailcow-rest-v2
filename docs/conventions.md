# Cross-feature conventions

These decisions apply to every feature. They were collected while features 002–013 were being
specified. A feature spec governs its own behaviour. Where a spec is silent, or contradicts
another spec, this document applies, and the next change to that spec aligns it. Every plan reads
this document next to the constitution.

## Scope and errors

- An object outside the caller's scope answers **"not found"**, exactly like an object that does
  not exist. **"Forbidden"** is only for a role, permission flag or key scope that may never use
  the operation.
- When any entry of a request is invalid or not permitted, the whole request is refused and every
  bad entry is named. Legacy instead drops the bad entries and saves the rest.
- Invalid input is **refused with field errors** (FR-055 of 001: a stable rule code plus its
  parameters). Legacy silently clamps or coerces such values, and the spec marks the difference as
  a deviation.

## API keys

- A key acts as its owner (an admin or a domain admin), and a read-only key may only read.
- The following operations need an interactive administrator session and are never allowed with a
  key:
  - creating and revoking keys (001);
  - writing admin accounts (007);
  - sending and cancelling mass mail, and setting the Rspamd UI password (013).
- Impersonation is never possible with a key.

## Bulk actions

- They exist where legacy offers mass actions, with these rules:
  - at most 100 objects per request, or 1,000 queue IDs for the mail queue (011);
  - the whole request is refused only when the role, flag or key scope does not allow the action
    at all;
  - otherwise each object is authorised, validated, processed and audited on its own, and gets one
    result: `done`, `notFound`, `refused`, `outdated` or `failed`;
  - one failure does not stop the others.
- Each item **may carry its own version tag**. If the tag is outdated, that item gets `outdated`
  and the others proceed. Items without a tag are not version-checked.
- Creation is always one object per request. An object named twice is processed once.

## Version tags (ETag)

- A version tag is a strong ETag over the **stored settings** of the resource. It never covers
  usage figures or runtime status.
- `If-Match` is optional, and an outdated tag answers 412.
- Every list entry carries its version tag.
- When two features expose the same stored values, they compute the same tag. For example, the
  password policy read in 003 and the policy edit in 013 return the same tag.

## Secrets

- Stored secrets are **write-only**. A response says only whether one is set. This covers
  relayhost and transport passwords, sync job remote passwords, identity provider secrets and LDAP
  bind passwords.
- Generated secrets are shown exactly once: API key secrets, app passwords, OAuth2 client secrets
  (at creation and when regenerated) and TOTP enrolment data.
- Omitting a secret on edit keeps the stored value. It must be sent again when the address that
  receives it changes (012).
- Secrets never appear in logs or audit records. Legacy log content shown through 011 is masked.

## Legacy configuration the API cannot read

- Values from legacy's PHP configuration (`vars.inc.php`, `vars.local.inc.php`) become **API
  server settings** with upstream defaults. When mailcow's `data/web/inc` is mounted read-only,
  001's configuration reader takes the operator's current values from those files, and a
  `RESTV2_<VAR>` setting always wins. Features reuse this reader; none writes its own. Examples are `ALIAS_DISABLE_EXTERNAL_DOMAINS`,
  `SHOW_DKIM_PRIV_KEYS`, `ALLOW_ADMIN_EMAIL_LOGIN`, `DOCKER_TIMEOUT` and `IMAPSYNC_OPTIONS`. The
  operator keeps them aligned with legacy when the files are not mounted.
- Values that live in `mailcow.conf` reuse its variable names.
- FeldHost fork behaviour is a server setting whose default is upstream behaviour. Examples are
  the default DKIM selector, the ISPConfig DNS push, the update check, license verification and
  the `WEBUI` recent-login label.
- Clients that need such a setting before they submit read it from the owning feature's
  capabilities read (for example 002 FR-064).

## Settings owned by another feature

- A setting owned by another feature may be given **at creation**, wherever legacy's add form or
  templates accept it. Afterwards it is read-only, and the owning feature edits it.
- Creation follow-up steps run after the primary object is saved atomically. Examples are DKIM
  generation, the initial rate limit and the SOGo restart. Each step's outcome is reported, and a
  failing step never rolls back the primary object.
- Cleanup that more than one feature performs is implemented once and reused. Alias-domain cleanup
  serves both 002 (domain deletion) and 004. BCC maps are matched in the `@domain` form.

## Audit, reads and freshness

- Every state change writes one audit record per affected object, carrying the bulk request ID
  when there is one.
- Reads are not audited, except views of sensitive content: queued message content (011) and
  private key export (010).
- Data is read from mailcow's database and Redis on every request, with no caching, unless a spec
  sets a maximum age (011 sets them).
- Long operations that go through dockerapi are synchronous, bounded by `DOCKER_TIMEOUT` (60 s),
  and answer "outcome unknown" on timeout. The exception is a spec that defines a background job,
  such as mass mail in 013.

## Lists

- `limit` defaults to 25 and its maximum is 1,000, the same for every list, so that automation
  clients need few round trips. `offset` starts at 0. Lists accept `sort`, `order`, documented
  filters and a substring search (`q`). Clients such as the web app offer smaller page sizes (25,
  50, 100).

## Shared contract components

- **Bulk actions:**
  - request items use `api/components/schemas/BulkTarget.yaml`, or extend it with `allOf`;
  - the response is `api/components/responses/BulkResultResponse.yaml`, built from `BulkResult`,
    `BulkItemResult` and `BulkItemOutcome`.
- **Search:** lists use `api/components/parameters/Search.yaml` (`q`), next to `Limit`, `Offset`
  and `Order`. Each feature defines its own `sort` parameter with its sortable fields.
- **Shared enumerations:** each feature appends its values to `KnownProblemType`,
  `AuditEventType`, `ValidationRuleCode` and, where needed, `AuditTarget`. Values are never
  removed or renamed.
- **New components:** each one carries its resource's name (for example `DomainTag`,
  `MailboxTag`, `DomainCapabilities`), so that features never collide.

## Capabilities, history and refusals

- **Capabilities reads** use the top-level path `/capabilities/{area}`, for example
  `/capabilities/domains`, `/capabilities/mailboxes` and `/capabilities/sync-jobs`. This never
  collides with object identifiers: single-label domain names such as "capabilities" are valid.
  The operation ID is `get<Area>Capabilities`. Read-only keys may use them, and they never contain
  secrets. Helper lists such as distinct tags use top-level paths too (`/domain-tags`).
- **History:** clients filter `GET /audit-events` with the generic `targetType` and `targetId`
  parameters. A feature adds values to `AuditTarget` and never adds its own audit filter
  parameter. Impersonation filters belong to 007.
- **Shared enumerations** also include `AuditFailureReason`, which is append-only like the others.
- **Refusal auditing:** state-changing routes are marked so that refusals by authenticated callers
  are audited. This covers read-only keys, restricted sessions, missing flags and out-of-scope
  objects, but not CSRF failures or 401s. Use 008's `RefusalAuditor` mechanism, or the equivalent
  that the first implemented feature creates.
- **Version tags** never contain a plain hash of a stored secret. They use an HMAC keyed with the
  app key.
- **Uniqueness that upstream enforces only in code** is protected with MariaDB `GET_LOCK()` around
  check-and-insert, never with a new index on an upstream table.

## Bulk paths and identity changes

- **Bulk actions** use `POST /{collection}/bulk-actions`. The path is POST-only, and object paths
  never accept POST, so an object named `bulk-actions` stays addressable.
- **Identity changes** (impersonation start or end) renew the CSRF token. Clients re-read
  `GET /me` before retrying a write that failed with `csrf-token-mismatch`.
- **Sign-out and ending an impersonation** always succeed, even when an impersonation has become
  invalid. They end and audit it.
- **Bulk request bodies** use the member name `targets`: `{"action": ..., "targets": [BulkTarget, ...]}`.

## Permission refusals

- `403 /api/v2/problems/forbidden`: the caller's role or key type may never use the operation.
- `403 /api/v2/problems/permission-flag-required`: the operation needs a permission flag (`da_acl`
  or `user_acl`) that the caller lacks. The problem lists the flags in a `requiredFlags` array (any
  one of them would allow the operation), as `docs/problems.md` documents. Flag values are always
  the property names of 001's `PermissionFlags` schema (camelCase, for example
  `quarantineAttachments`, `loginAs`, `appPasswds`, `syncjobs`), never legacy column names, so
  that clients can compare them with `GET /me`.
- `403 /api/v2/problems/attribute-not-permitted`: the request sets a field that the caller's flags
  don't allow. The problem names each field and its flag.
- Objects outside the caller's scope stay `404`, as described above.
- Single-resource responses may carry `meta` next to `data` when an operation reports side
  information, for example affected objects.

## Shared locks and helpers

- **Address uniqueness** across mailboxes, aliases, alias domains and temporary aliases uses 003's
  `AddressLock`, which is `GET_LOCK('restv2_address_<sha1 of the lower-cased address>')`. Features
  003, 004 and 005 must take exactly this lock name before checking for and inserting an address.
- **Shared helpers belong to the first feature that needs them**, and later features reuse them.
  002 owns `DockerApiClient`, `SogoDirectory`, `AliasDomainCleanup`, `RateLimitStore`, the DKIM
  classes and the ISPConfig client. 003 owns `AddressLock`, `NamedLock`, `TagSet`,
  `BulkActionRunner`, `MailboxPasswordHasher` and `PasswordPolicyChecker`. 008 owns
  `RefusalAuditor`, unless an earlier feature creates it first. 009 owns `app/Clients/Dns` and
  `app/Clients/Smtp`.
